<?php

/** Real HTTP uploads: exercise PHP's temporary-file provenance checks, persistence and rollback. */

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Uploads\IncomingFile;

final class UploadHttpTest extends TestCase
{
    private static mixed $server;
    private static string $base;
    private static string $key;
    private static string $directory;
    private static \Closure $uploadDirectory;
    private FormService $forms;
    private int $formId;
    /** @var array<string, mixed> */
    private array $document;

    public static function setUpBeforeClass(): void
    {
        self::$key = bin2hex(random_bytes(24));
        self::$directory = sys_get_temp_dir() . '/swf-http-' . bin2hex(random_bytes(8));
        mkdir(self::$directory);
        self::$uploadDirectory = static function (array $directory): array {
            $directory['basedir'] = self::$directory;
            $directory['path'] = self::$directory;
            return $directory;
        };
        add_filter('upload_dir', self::$uploadDirectory);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot allocate an HTTP test port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;
        putenv('SWF_TEST_HTTP_KEY=' . self::$key);
        putenv('SWF_TEST_UPLOAD_ROOT=' . self::$directory);
        $log = self::$directory . '/server.log';
        self::$server = proc_open([PHP_BINARY, '-d', 'post_max_size=2M', '-d', 'upload_max_filesize=2M', '-S', $address, '-t', getenv('WP_ROOT'), dirname(__DIR__) . '/Support/upload-router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource(self::$server)) {
            throw new \RuntimeException('Cannot start PHP HTTP fixture.');
        }
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('HTTP fixture failed to become ready: ' . file_get_contents($log));
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        remove_filter('upload_dir', self::$uploadDirectory);
        foreach (glob(self::$directory . '/swforms/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir(self::$directory . '/swforms')) {
            rmdir(self::$directory . '/swforms');
        }
        unlink(self::$directory . '/server.log');
        rmdir(self::$directory);
        putenv('SWF_TEST_HTTP_KEY');
        putenv('SWF_TEST_UPLOAD_ROOT');
    }

    protected function setUp(): void
    {
        $this->forms = FormService::create_default();
        $this->formId = $this->forms->create('HTTP upload test');
        $this->document = ['fields' => [
            ['id' => 'upload', 'type' => 'file', 'key' => 'documents', 'required' => true, 'settings' => ['extensions' => 'txt,pdf,png,jpg,csv,zip', 'multiple' => true, 'maxFiles' => 2, 'maxSize' => 1]],
            ['id' => 'message', 'type' => 'input', 'key' => 'message', 'label' => 'Message', 'required' => true],
            ['id' => 'hidden', 'type' => 'file', 'key' => 'hidden', 'required' => true, 'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'message', 'op' => 'is', 'value' => 'reveal']]]],
            ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'settings' => ['repeatable' => true, 'minRows' => 0, 'maxRows' => 3], 'children' => [['id' => 'attachment', 'type' => 'file', 'key' => 'attachment']]],
        ], 'settings' => ['storeEntries' => true, 'notifications' => [], 'spam' => ['minSeconds' => 0, 'rateLimit' => 0, 'honeypot' => true], 'confirmation' => ['message' => 'HTTP upload saved']]];
        self::assertSame([], $this->forms->save($this->formId, 'HTTP upload test', $this->document)['errors']);
    }

    protected function tearDown(): void
    {
        $this->forms->repository()->delete($this->formId);
        self::assertSame([], $this->storedFiles(), 'Test leaked a persistent upload.');
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return glob(self::$directory . '/swforms/swf-*') ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return (new EntryRepository())->search($this->formId, 'inbox', '', 1, 50, 'desc')['items'];
    }

    /**
     * @param list<array{name: string, content: string, field?: string}> $files
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function submit(array $files, array $fields = [], string $fault = '', bool $postback = false): array
    {
        $fields += ['swf_token' => (new SpamGuard())->issue_token($this->formId), 'swf[message]' => 'hello'];
        $boundary = 'swf-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $file) {
            $name = $file['field'] ?? 'swf[upload][]';
            $filename = $file['name'];
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $file['content'] . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
        $path = $postback ? '/?swf_form=' . $this->formId : '/?rest_route=/swforms/v1/forms/' . $this->formId . '/submit';
        $curl = curl_init(self::$base . $path);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary=' . $boundary, 'X-SWF-Test-Key: ' . self::$key, 'X-SWF-Test-Fault: ' . $fault]]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        self::assertIsString($response, $error);
        if ($postback) {
            return ['html' => $response, 'http' => $status];
        }
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result + ['http' => $status];
    }

    public function testMultipartFilesAndSparseRepeaterRowsAreStoredWithActualMetadata(): void
    {
        $result = $this->submit([
            ['name' => 'first.txt', 'content' => 'First document'],
            ['name' => 'second.csv', 'content' => "name,value\nexample,1"],
            ['name' => 'row.txt', 'content' => 'Row document', 'field' => 'swf[rows][19][attachment][]'],
        ]);
        self::assertSame('success', $result['status'], json_encode($result));
        $entry = (new EntryRepository())->find($this->entries()[0]['id']);
        self::assertSame('first.txt', $entry['data']['documents'][0]['name']);
        self::assertSame('text/plain', $entry['data']['documents'][0]['type']);
        self::assertSame(14, $entry['data']['documents'][0]['size']);
        self::assertSame('row.txt', $entry['data']['rows'][0]['attachment'][0]['name']);
        self::assertCount(3, $this->storedFiles());
        self::assertArrayNotHasKey('hidden', $entry['data']);
        self::assertSame('', file_get_contents(self::$directory . '/swforms/index.html'));
        foreach ($entry['meta']['uploads'] as $name) {
            self::assertMatchesRegularExpression('/^swf-[a-f0-9]{32}\.(txt|csv)$/', $name);
            self::assertFileExists(self::$directory . '/swforms/' . $name);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFiles(): array
    {
        return [
            'forbidden extension' => ['script.php', '<?php echo 1;'],
            'MIME does not match PDF' => ['fake.pdf', 'This is plain text.'],
            'empty' => ['empty.txt', ''],
            'per-field size exceeded' => ['large.txt', str_repeat('x', 1024 * 1024 + 1)],
            'image extension mismatch' => ['fake.jpg', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zQAAAABJRU5ErkJggg==')],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFilesNeverCreateEntriesOrPersistentFiles(string $name, string $content): void
    {
        $result = $this->submit([['name' => $name, 'content' => $content]]);
        self::assertSame(422, $result['http']);
        self::assertArrayHasKey('upload', $result['errors']);
        self::assertSame([], $this->entries());
        self::assertSame([], $this->storedFiles());
    }

    public function testFileCountCannotBeBypassedWithMultipartArrayParts(): void
    {
        $result = $this->submit(array_fill(0, 3, ['name' => 'one.txt', 'content' => 'one']));
        self::assertSame(422, $result['http']);
        self::assertArrayHasKey('upload', $result['errors']);
        self::assertSame([], $this->storedFiles());
    }

    public function testOtherFieldErrorsPreventFileMoves(): void
    {
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one']], ['swf[message]' => '']);
        self::assertArrayHasKey('message', $result['errors']);
        self::assertSame([], $this->entries());
        self::assertSame([], $this->storedFiles());
    }

    public function testHiddenFilesAreIgnoredEvenWhenTheyWouldFailValidation(): void
    {
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one'], ['name' => 'script.php', 'content' => '<?php', 'field' => 'swf[hidden][]']]);
        self::assertSame('success', $result['status'], json_encode($result));
        self::assertCount(1, $this->storedFiles());
    }

    public function testPlainValuesCannotClaimAnUploadedFile(): void
    {
        $result = $this->submit([], ['swf[upload][]' => '/etc/passwd']);
        self::assertArrayHasKey('upload', $result['errors']);
        self::assertSame([], $this->entries());
    }

    public function testUploadsRequireEntryStorage(): void
    {
        $this->document['settings']['storeEntries'] = false;
        $this->forms->save($this->formId, 'HTTP upload test', $this->document);
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one']]);
        self::assertArrayHasKey('upload', $result['errors']);
        self::assertSame([], $this->storedFiles());
    }

    public function testSpamChecksRunBeforeFilePersistence(): void
    {
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one']], ['swf_website' => 'bot']);
        self::assertSame('success', $result['status'], json_encode($result));
        self::assertSame([], $this->entries());
        self::assertSame([], $this->storedFiles());
    }

    public function testInvalidTokenCannotPersistFiles(): void
    {
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one']], ['swf_token' => 'invalid']);
        self::assertSame('invalid_token', $result['code']);
        self::assertSame([], $this->storedFiles());
    }

    /** @return list<array{string, string}> */
    public static function persistenceFailures(): array
    {
        return [['second-upload', 'upload'], ['missing-file', 'upload'], ['missing-url', 'upload'], ['entry-insert', 'storage'], ['data-filter', 'storage'], ['directory', 'upload']];
    }

    #[DataProvider('persistenceFailures')]
    public function testFailedPersistenceRollsBackTheWholeUploadBatch(string $fault, string $code): void
    {
        $result = $this->submit([['name' => 'one.txt', 'content' => 'one'], ['name' => 'two.txt', 'content' => 'two']], [], $fault);
        self::assertSame('error', $result['status'], json_encode($result));
        self::assertSame($code, $result['code']);
        self::assertSame([], $this->entries());
        self::assertSame([], $this->storedFiles());
    }

    public function testNativePostbackSavesTheSameFileValue(): void
    {
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Upload HTTP fixture', 'post_content' => '[sw_form id="' . $this->formId . '"]']);
        try {
            $result = $this->submit([['name' => 'one.txt', 'content' => 'one']], ['swf_form_id' => (string) $this->formId], '', true);
            self::assertSame(200, $result['http']);
            self::assertCount(1, $this->entries());
            self::assertCount(1, $this->storedFiles());
        } finally {
            wp_delete_post($page, true);
        }
    }

    public function testMalformedMultipartMetadataFailsClosed(): void
    {
        $raw = IncomingFile::merge(['keep' => 'yes'], ['name' => ['upload' => [null, 'bad.txt']], 'tmp_name' => 'invalid', 'error' => ['upload' => ['not-an-integer', UPLOAD_ERR_NO_FILE]]]);
        self::assertSame('yes', $raw['keep']);
        $files = IncomingFile::files($raw['upload']);
        self::assertCount(1, $files);
        self::assertSame(UPLOAD_ERR_PARTIAL, $files[0]->error);
        self::assertSame('', $files[0]->temporary);
    }
    /** @return list<array{string, string, string}> */
    public static function imageFormats(): array
    {
        return [['png', 'imagepng', 'image/png'], ['jpg', 'imagejpeg', 'image/jpeg'], ['jpeg', 'imagejpeg', 'image/jpeg'], ['gif', 'imagegif', 'image/gif'], ['webp', 'imagewebp', 'image/webp']];
    }

    #[DataProvider('imageFormats')]
    public function testImageFormatsUseTheExistingUploadPipeline(string $extension, string $encode, string $mime): void
    {
        $this->document['fields'][0]['type'] = 'image';
        unset($this->document['fields'][0]['settings']['extensions']);
        $this->forms->save($this->formId, 'Image upload test', $this->document);
        $image = imagecreatetruecolor(2, 3);
        ob_start();
        $encode($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $result = $this->submit([['name' => 'photo.' . $extension, 'content' => $png]]);
        self::assertSame('success', $result['status'], json_encode($result));
        $entry = (new EntryRepository())->find($this->entries()[0]['id']);
        self::assertSame($mime, $entry['data']['documents'][0]['type']);
        self::assertSame('photo.' . $extension, $entry['data']['documents'][0]['name']);
        self::assertCount(1, $this->storedFiles());
        self::assertSame([2, 3], array_slice(getimagesize($this->storedFiles()[0]), 0, 2));
    }

    public function testImageFieldsRejectNonImagesEvenWhenTheirExtensionWasConfigured(): void
    {
        $this->document['fields'][0]['type'] = 'image';
        $this->forms->save($this->formId, 'Image upload test', $this->document);
        $result = $this->submit([['name' => 'note.txt', 'content' => 'plain text']]);
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('upload', $result['errors']);
        self::assertSame([], $this->storedFiles());
    }

    public function testImageFieldsRejectHeadersWithoutPositiveImageDimensions(): void
    {
        $this->document['fields'][0]['type'] = 'image';
        $this->forms->save($this->formId, 'Image upload test', $this->document);
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 0) . pack('N', 0) . "\x08\x06\x00\x00\x00";
        $result = $this->submit([['name' => 'empty-dimensions.png', 'content' => $header]]);
        self::assertSame('invalid', $result['status']);
        self::assertStringContainsString('valid JPEG', $result['errors']['upload']);
        self::assertSame([], $this->entries());
    }

    /** @return list<array{string, string}> */
    public static function mediaFiles(): array
    {
        return [['audio', 'audio.webm'], ['audio', 'audio.ogg'], ['audio', 'audio.mp3'], ['audio', 'audio.wav'], ['audio', 'audio.m4a'], ['audio', 'audio.mp4'], ['video', 'video.webm'], ['video', 'video.mp4']];
    }

    #[DataProvider('mediaFiles')]
    public function testRealMediaStreamsAreStoredAndDeleted(string $type, string $file): void
    {
        $this->document['fields'][0]['type'] = $type;
        $this->document['fields'][0]['settings'] = [];
        self::assertSame([], $this->forms->save($this->formId, 'Media test', $this->document)['errors']);
        $bytes = file_get_contents(dirname(__DIR__) . '/fixtures/media/' . $file);
        $result = $this->submit([['name' => $file, 'content' => $bytes]]);
        self::assertSame('success', $result['status'], json_encode($result));
        self::assertCount(1, $this->storedFiles());
        self::assertSame($bytes, file_get_contents($this->storedFiles()[0]));
        $entry = (new EntryRepository())->find($this->entries()[0]['id']);
        self::assertSame($file, $entry['data']['documents'][0]['name']);
        self::assertSame(strlen($bytes), $entry['data']['documents'][0]['size']);
    }

    /** @return list<array{string, string}> */
    public static function mismatchedStreams(): array
    {
        return [['audio', 'video.webm'], ['audio', 'video.mp4'], ['video', 'audio.webm'], ['video', 'audio.mp4']];
    }

    #[DataProvider('mismatchedStreams')]
    public function testWrongStreamKindIsRejectedEvenInAnAllowedContainer(string $type, string $file): void
    {
        $this->document['fields'][0]['type'] = $type;
        $this->document['fields'][0]['settings'] = [];
        $this->forms->save($this->formId, 'Media test', $this->document);
        $result = $this->submit([['name' => $file, 'content' => file_get_contents(dirname(__DIR__) . '/fixtures/media/' . $file)]]);
        self::assertSame('invalid', $result['status'], json_encode($result));
        self::assertStringContainsString('stream', $result['errors']['upload']);
        self::assertSame([], $this->entries());
        self::assertSame([], $this->storedFiles());
    }

    public function testSignatureAcceptsOnlyAPngAndPreservesTheExistingStorageContract(): void
    {
        $this->document['fields'][0]['type'] = 'signature';
        $this->document['fields'][0]['settings'] = ['extensions' => 'txt,png', 'multiple' => true];
        $this->forms->save($this->formId, 'Signature test', $this->document);
        $result = $this->submit([['name' => 'fake.png', 'content' => 'not a signature']]);
        self::assertSame('invalid', $result['status']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zQAAAABJRU5ErkJggg==');
        $result = $this->submit([['name' => 'signature.png', 'content' => $png]]);
        self::assertSame('success', $result['status'], json_encode($result));
        self::assertSame($png, file_get_contents($this->storedFiles()[0]));
    }
}
