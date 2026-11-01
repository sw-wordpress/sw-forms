<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Actions\ActionInterface;
use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\Steps;
use SWForms\WordPress\Rest\SubmitController;
use SWForms\WordPress\Submission\Submission;
use SWForms\WordPress\Submission\SubmissionService;
use SWForms\WordPress\Template\MergeTagResolver;
use SWForms\WordPress\Uploads\UploadStorage;
use SWForms\WordPress\Webhooks\Transport;

/** Regression tests for adapter code tightened while extending PHPStan to src/WordPress. */
final class AdapterTypeSafetyTest extends TestCase
{
    public function testResponseWithoutStatusCodeIsReportedAsIntegerZero(): void
    {
        $http = static fn ($pre, $args, $url) => $url === 'https://93.184.216.34/status-less'
            ? ['headers' => [], 'body' => 'no status', 'cookies' => []]
            : $pre;
        add_filter('pre_http_request', $http, 10, 3);
        try {
            $result = (new Transport())->send(['url' => 'https://93.184.216.34/status-less']);
        } finally {
            remove_filter('pre_http_request', $http, 10);
        }
        // wp_remote_retrieve_response_code() returns '' here; the result contract (and the
        // unsigned response_code column) require an integer.
        self::assertSame(0, $result['code']);
        self::assertFalse($result['success']);
        self::assertSame('no status', $result['message']);
    }

    public function testStepGroupsLabelFallbackAndErrorScoping(): void
    {
        $tree = Tree::build([
            ['id' => 'first', 'type' => 'input', 'key' => 'first'],
            ['id' => 'break1', 'type' => 'page-break', 'label' => 'Second'],
            ['id' => 'second', 'type' => 'input', 'key' => 'second'],
            ['id' => 'break2', 'type' => 'page-break', 'label' => '  '],
            ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'child', 'type' => 'input', 'key' => 'child'],
            ]],
        ], FieldRegistry::instance());
        self::assertSame([
            ['label' => 'Intro', 'ids' => ['first']],
            ['label' => 'Second', 'ids' => ['second']],
            ['label' => 'Step 3', 'ids' => ['rows']],
        ], Steps::groups($tree, ['steps' => ['enabled' => true, 'firstLabel' => 'Intro']]));
        self::assertSame([['label' => 'Step 1', 'ids' => ['first', 'second', 'rows']]], Steps::groups($tree, ['steps' => ['enabled' => false]]));

        $errors = ['first' => 'a', 'second' => 'b', 'rows[0].child' => 'c', 'rows.child' => 'd', 'other' => 'e'];
        self::assertSame(['rows[0].child' => 'c', 'rows.child' => 'd'], Steps::errors($tree, $errors, ['rows']));
        self::assertSame(['first' => 'a'], Steps::errors($tree, $errors, ['first']));
    }

    public function testMergeTagsIndexColumnsAndPlainGroupsButNotRepeaterRows(): void
    {
        $document = ['fields' => [
            'not-an-element',
            ['id' => 'cols', 'type' => 'columns', 'columns' => [
                ['id' => 'c1', 'children' => [['id' => 'left', 'type' => 'input', 'key' => 'left']]],
            ]],
            ['id' => 'person', 'type' => 'group', 'key' => 'person', 'children' => [
                ['id' => 'given', 'type' => 'input', 'key' => 'given'],
            ]],
            ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'item', 'type' => 'input', 'key' => 'item'],
            ]],
        ], 'settings' => []];
        $registry = FieldRegistry::instance();
        $tree = Tree::build($document['fields'], $registry);
        $data = ['left' => 'L', 'person' => ['given' => 'Ada'], 'rows' => [['item' => 'x']]];
        $submission = new Submission(['id' => 1, 'title' => 'T'], 1, $document, $tree, $data, (new Evaluator($tree))->evaluate($data), $data, 0, new EntryRepository());
        $resolver = new MergeTagResolver($submission, $registry);
        self::assertSame('L / Ada / ', $resolver->replace('{field:left} / {field:person.given} / {field:rows.item}', MergeTagResolver::CONTEXT_TEXT));
    }

    public function testRegisteredActionsFilterOutputIsValidated(): void
    {
        $method = new \ReflectionMethod(SubmissionService::class, 'valid_actions');
        self::assertSame([], $method->invoke(null, 'not an array'));
        $action = new class () implements ActionInterface {
            public function type(): string
            {
                return 'test';
            }

            public function run(Submission $submission): void
            {
            }
        };
        self::assertSame([$action], $method->invoke(null, ['x' => 'junk', 'y' => $action, 'z' => new \stdClass()]));
    }

    public function testJsonBodyThatIsNotAnObjectBecomesAnEmptyArray(): void
    {
        $method = new \ReflectionMethod(SubmitController::class, 'body');
        foreach (['5', '"text"', 'null', ''] as $body) {
            $request = new \WP_REST_Request('POST', '/swforms/v1/test');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body($body);
            self::assertSame([], $method->invoke(new SubmitController(), $request), $body);
        }
        $request = new \WP_REST_Request('POST', '/swforms/v1/test');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"a":1}');
        self::assertSame(['a' => 1], $method->invoke(new SubmitController(), $request));
    }

    public function testUploadManifestsFromStoredEntriesIgnoreNonGeneratedNames(): void
    {
        $dir = wp_upload_dir()['basedir'] . '/swforms';
        wp_mkdir_p($dir);
        $name = 'swf-' . bin2hex(random_bytes(16)) . '.txt';
        file_put_contents($dir . '/' . $name, 'x');
        try {
            $manifest = [['nested'], 42, null, '../wp-config.php', $name];
            self::assertSame([$dir . '/' . $name], UploadStorage::attachments($manifest));
            UploadStorage::delete($manifest);
            self::assertFileDoesNotExist($dir . '/' . $name);
        } finally {
            if (is_file($dir . '/' . $name)) {
                unlink($dir . '/' . $name);
            }
        }
    }

    public function testMissingTemporaryUploadFailsBeforeWordPressHandlesIt(): void
    {
        $method = new \ReflectionMethod(UploadStorage::class, 'move');
        $type = FieldRegistry::instance()->get('file');
        self::assertInstanceOf(\SWForms\WordPress\Fields\FileField::class, $type);
        $storage = new UploadStorage();
        $filters = static fn (): int => isset($GLOBALS['wp_filter']['upload_dir']) ? count($GLOBALS['wp_filter']['upload_dir']->callbacks, COUNT_RECURSIVE) : 0;
        $before = $filters();
        try {
            $method->invoke($storage, new \SWForms\WordPress\Uploads\IncomingFile('a.txt', '/nonexistent/php-upload', UPLOAD_ERR_OK), $type, ['id' => 'f', 'type' => 'file']);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Upload could not be stored.', $exception->getMessage());
        }
        self::assertSame([], $storage->manifest());
        self::assertSame($before, $filters(), 'The scoped upload_dir filter must be removed.');
    }

    public function testMigrationRunsPendingStepsAndRecordsCurrentVersion(): void
    {
        global $wpdb;
        $installed = get_option(Schema::VERSION_OPTION);
        try {
            update_option(Schema::VERSION_OPTION, 1, true);
            self::assertTrue(Schema::needs_migration());
            Schema::migrate();
            self::assertSame(Schema::CURRENT, (int) get_option(Schema::VERSION_OPTION));
            self::assertFalse(Schema::needs_migration());
            self::assertSame(Schema::table('webhook_deliveries'), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Schema::table('webhook_deliveries'))));
        } finally {
            update_option(Schema::VERSION_OPTION, $installed, true);
        }
    }

    public function testBlockIsRegisteredWithIntegerApiVersion(): void
    {
        $block = \WP_Block_Type_Registry::get_instance()->get_registered('swforms/form');
        self::assertNotNull($block);
        self::assertSame(3, $block->api_version);
        self::assertSame('', render_block(['blockName' => 'swforms/form', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]));
    }
}
