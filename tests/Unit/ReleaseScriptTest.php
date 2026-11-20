<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs bin/release.sh against disposable repositories and a local bare origin; nothing is published. */
final class ReleaseScriptTest extends TestCase
{
    private string $root;
    private string $work;
    private string $origin;

    protected function setUp(): void
    {
        $this->root   = sys_get_temp_dir() . '/swf-release-' . bin2hex(random_bytes(6));
        $this->origin = $this->root . '/origin.git';
        $this->work   = $this->root . '/work';
        mkdir($this->work . '/bin', 0777, true);
        copy(dirname(__DIR__, 2) . '/bin/release.sh', $this->work . '/bin/release.sh');
        file_put_contents(
            $this->work . '/sw-forms.php',
            "<?php\n/**\n * Plugin Name:       SW Forms\n * Version:           0.5.0\n */\n\ndefine( 'SWF_VERSION', '0.5.0' );\n"
        );
        $this->sh('git init -q --bare ' . escapeshellarg($this->origin), $this->root);
        $this->sh('git init -q -b main && git remote add origin ' . escapeshellarg($this->origin) . ' && git add -A && git commit -q -m init && git push -q origin main', $this->work);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testReleasePinsVersionCommitsTagsAndPushesAtomically(): void
    {
        [$code] = $this->release('0.6.0');
        self::assertSame(0, $code);
        $source = (string) file_get_contents($this->work . '/sw-forms.php');
        self::assertStringContainsString(' * Version:           0.6.0', $source);
        self::assertStringContainsString("define( 'SWF_VERSION', '0.6.0' );", $source);
        [, $log] = $this->sh('git log -1 --format=%s', $this->work);
        self::assertSame('Release 0.6.0', trim($log));
        [, $remote] = $this->sh('git --git-dir=' . escapeshellarg($this->origin) . ' tag -l 0.6.0 && git --git-dir=' . escapeshellarg($this->origin) . ' log -1 --format=%s main', $this->root);
        self::assertSame("0.6.0\nRelease 0.6.0", trim($remote));
    }

    public function testSameVersionTagsWithoutAnExtraCommit(): void
    {
        [$code] = $this->release('0.5.0');
        self::assertSame(0, $code);
        [, $log] = $this->sh('git log --format=%s', $this->work);
        self::assertSame('init', trim($log));
        [, $tags] = $this->sh('git tag -l', $this->work);
        self::assertSame('0.5.0', trim($tags));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['1.2'];
        yield 'prefix' => ['v1.2.3'];
        yield 'suffix' => ['1.2.3-beta'];
    }

    #[DataProvider('invalidVersions')]
    public function testRejectsInvalidVersionsWithoutChangingAnything(string $version): void
    {
        [$code, $output] = $this->release($version);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: make release VERSION=X.Y.Z', $output);
        $this->assertUntouched();
    }

    public function testRejectsDirtyWorkingTreeIncludingUntrackedFiles(): void
    {
        file_put_contents($this->work . '/untracked.txt', 'x');
        [$code, $output] = $this->release('0.6.0');
        self::assertSame(1, $code);
        self::assertStringContainsString('Commit or stash all changes', $output);
        self::assertStringContainsString(' * Version:           0.5.0', (string) file_get_contents($this->work . '/sw-forms.php'));
    }

    public function testRejectsExistingLocalAndRemoteTags(): void
    {
        $this->sh('git tag 0.6.0', $this->work);
        [$code, $output] = $this->release('0.6.0');
        self::assertSame(1, $code);
        self::assertStringContainsString('already exists locally', $output);

        $this->sh('git tag -d 0.6.0 && git tag 0.7.0 && git push -q origin 0.7.0 && git tag -d 0.7.0', $this->work);
        [$code, $output] = $this->release('0.7.0');
        self::assertSame(1, $code);
        self::assertStringContainsString('already exists on origin', $output);
    }

    public function testRejectsDowngradeAndInconsistentDeclarations(): void
    {
        [$code, $output] = $this->release('0.4.0');
        self::assertSame(1, $code);
        self::assertStringContainsString('Invalid or inconsistent plugin version, or requested downgrade', $output);
        $this->assertUntouched();

        $source = str_replace("'SWF_VERSION', '0.5.0'", "'SWF_VERSION', '0.4.9'", (string) file_get_contents($this->work . '/sw-forms.php'));
        file_put_contents($this->work . '/sw-forms.php', $source);
        $this->sh('git commit -q -am drift', $this->work);
        [$code] = $this->release('0.6.0');
        self::assertSame(1, $code);
        [, $tags] = $this->sh('git tag -l', $this->work);
        self::assertSame('', trim($tags));
    }

    private function assertUntouched(): void
    {
        [, $status] = $this->sh('git status --porcelain', $this->work);
        [, $tags] = $this->sh('git tag -l', $this->work);
        [, $log] = $this->sh('git log --format=%s', $this->work);
        self::assertSame('', trim($status));
        self::assertSame('', trim($tags));
        self::assertSame('init', trim($log));
    }

    /** @return array{int, string} */
    private function release(string $version): array
    {
        return $this->sh('bash bin/release.sh', $this->work, ['SWF_RELEASE_VERSION' => $version]);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function sh(string $command, string $cwd, array $env = []): array
    {
        $identity = [
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.org',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.org',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];
        $process = proc_open(
            ['bash', '-c', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge(['PATH' => (string) getenv('PATH'), 'HOME' => $this->root], $identity, $env)
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
