<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Submission\SubmissionLock;
use SWForms\WordPress\Submission\SubmissionService;

final class SubmissionConcurrencyTest extends TestCase
{
    private array $forms = [];
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->forms as $id) {
            (new FormRepository())->delete($id);
        }
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function form(int $limit = 0): int
    {
        $service = FormService::create_default();
        $id = $service->create('Concurrency regression');
        $this->forms[] = $id;
        $saved = $service->save($id, 'Concurrency regression', [
            'fields' => [['id' => 'name', 'type' => 'input', 'key' => 'name', 'required' => true]],
            'settings' => ['storeEntries' => true, 'notifications' => [], 'spam' => ['minSeconds' => 0, 'rateLimit' => $limit]],
        ]);
        self::assertSame([], $saved['errors']);
        return $id;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/swf-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $this->directories[] = $directory;
        return $directory;
    }

    private function workers(int $form, array $tokens, string $mode = ''): array
    {
        $directory = $this->directory();
        $input = $directory . '/input.json';
        file_put_contents($input, json_encode(compact('form', 'tokens', 'directory', 'mode'), JSON_THROW_ON_ERROR));
        $workers = [];
        try {
            foreach (array_keys($tokens) as $index) {
                $pipes = [];
                $command = [PHP_BINARY];
                if (getenv('SWF_COVERAGE_DIR')) {
                    array_push($command, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/coverage/bootstrap.php');
                }
                array_push($command, dirname(__DIR__) . '/Support/concurrent-submission-worker.php', $input, (string) $index);
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/error-' . $index, 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1]];
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = count(glob($directory . '/ready-*') ?: []) === count($tokens);
                if (!$ready) {
                    usleep(1000);
                }
            } while (!$ready && microtime(true) < $deadline);
            self::assertTrue($ready, 'Both workers must reach the start barrier.');
            touch($directory . '/go');
            $results = [];
            foreach ($workers as [$process, $output]) {
                $body = stream_get_contents($output);
                fclose($output);
                self::assertSame(0, proc_close($process), (string) $body);
                if ($mode !== 'hold') {
                    $results[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $workers = [];
            return [$results, is_file($directory . '/actions') ? file($directory . '/actions') : []];
        } finally {
            foreach ($workers as [$process, $output]) {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    public function testConcurrentTokenReplayStoresAndRunsActionsExactlyOnce(): void
    {
        global $wpdb;
        $id = $this->form();
        $token = (new SpamGuard())->issue_token($id);
        [$results, $actions] = $this->workers($id, [$token, $token]);
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'success'));
        self::assertContains('duplicate', array_column($results, 'code'));
        self::assertCount(1, $actions);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('entries') . ' WHERE form_id = %d', $id)));
    }

    public function testIndependentTokensCannotExceedRateLimitOne(): void
    {
        $id = $this->form(1);
        $guard = new SpamGuard();
        [$results, $actions] = $this->workers($id, [$guard->issue_token($id), $guard->issue_token($id)]);
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'success'));
        self::assertContains('rate_limited', array_column($results, 'code'));
        self::assertCount(1, $actions);
    }

    public function testExitedWorkerDoesNotLeaveAStaleClaim(): void
    {
        $id = $this->form();
        $this->workers($id, [''], 'hold');
        $lock = SubmissionLock::acquire($id);
        self::assertNotNull($lock);
        $lock->release();
        $lock->release();
    }

    public function testValidationAndStorageFailureReleaseLockWithoutConsumingToken(): void
    {
        global $wpdb;
        $id = $this->form(1);
        $token = (new SpamGuard())->issue_token($id);
        $service = SubmissionService::create_default();
        $request = ['token' => $token, 'ip' => '192.0.2.16'];
        self::assertSame('invalid', $service->submit($id, [], $request)->status);
        $filter = static fn (string $sql): string => str_starts_with($sql, 'INSERT INTO `' . Schema::table('entries') . '`') ? 'SWF_INTENTIONAL_INVALID_SQL' : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            self::assertSame('storage', $service->submit($id, ['name' => 'Ada'], $request)->code);
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        self::assertSame('success', $service->submit($id, ['name' => 'Ada'], $request)->status);
        self::assertSame('duplicate', $service->submit($id, ['name' => 'Ada'], $request)->code);
    }

    public function testLockFailureRejectsBeforeSubmissionEffects(): void
    {
        $filter = static fn (string $sql): string => str_contains($sql, 'GET_LOCK(') ? 'SELECT NULL' : $sql;
        add_filter('query', $filter);
        try {
            self::assertSame('busy', SubmissionService::create_default()->submit($this->form(), ['name' => 'Ada'], [])->code);
        } finally {
            remove_filter('query', $filter);
        }
    }
}
