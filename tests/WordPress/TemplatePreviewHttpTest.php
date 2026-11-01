<?php

/**
 * Real HTTP preview of a starter template: the route must render the actual form of the catalog
 * inside the theme, without a submission token, and keep unknown ids at 404.
 */

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class TemplatePreviewHttpTest extends TestCase
{
    private static mixed $server = null;
    private static string $base;
    private static string $key;
    private static string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$key = bin2hex(random_bytes(24));
        self::$directory = sys_get_temp_dir() . '/swf-preview-' . bin2hex(random_bytes(8));
        mkdir(self::$directory);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot allocate an HTTP test port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;
        putenv('SWF_TEST_HTTP_KEY=' . self::$key);
        $log = self::$directory . '/server.log';
        self::$server = proc_open(
            [ PHP_BINARY, '-d', 'memory_limit=512M', '-S', $address, '-t', getenv('WP_ROOT'), dirname(__DIR__) . '/Support/preview-router.php' ],
            array(
                0 => array('pipe', 'r'),
                1 => array('file', $log, 'a'),
                2 => array('file', $log, 'a'),
            ),
            $pipes
        );
        if (!is_resource(self::$server)) {
            throw new \RuntimeException('Cannot start the preview HTTP fixture.');
        }
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('Preview HTTP fixture failed to become ready: ' . (string) file_get_contents($log));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach (glob(self::$directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(self::$directory);
        putenv('SWF_TEST_HTTP_KEY');
    }

    /** @return array{http: int, html: string} */
    private static function fetch(string $path): array
    {
        $curl = curl_init(self::$base . $path);
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => array( 'X-SWF-Test-Key: ' . self::$key ),
            )
        );
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        self::assertIsString($body, $error);
        return array( 'http' => $status, 'html' => $body );
    }

    public function testTheRouteRendersTheRealFormWithoutAnySubmissionSecret(): void
    {
        $response = self::fetch('/?swf_template_preview=contact');
        self::assertSame(200, $response['http'], substr($response['html'], 0, 300));
        self::assertStringContainsString('<title>Contact form</title>', $response['html']);
        self::assertStringContainsString('swf-template-preview__note', $response['html']);
        self::assertStringContainsString('class="swf-form', $response['html']);
        self::assertStringContainsString('name="swf[email]"', $response['html']);
        self::assertStringContainsString('"demo":true', $response['html']);
        // The preview is not a stored form: nothing here can be posted back or persisted.
        self::assertStringNotContainsString('swf_token', $response['html']);
        self::assertStringNotContainsString('swf_form_id', $response['html']);
    }

    public function testMultiStepTemplatesPreviewTheirStepsAndConditions(): void
    {
        $response = self::fetch('/?swf_template_preview=appointment');
        self::assertSame(200, $response['http']);
        self::assertSame(3, substr_count($response['html'], 'swf-steps__item'));
        self::assertSame(3, substr_count($response['html'], 'data-swf-step='));
        // A conditional field is rendered but starts hidden, so it cannot cause a required error.
        self::assertStringContainsString('data-swf-id="service_detail"', $response['html']);
        self::assertMatchesRegularExpression('/data-swf-id="service_detail"[^>]*\bhidden\b/', $response['html']);
    }

    public function testUploadsInATemplatePreviewCarryTheEngineUploadMarkup(): void
    {
        $response = self::fetch('/?swf_template_preview=job-application');
        self::assertSame(200, $response['http']);
        self::assertStringContainsString('type="file"', $response['html']);
        self::assertStringContainsString('accept="', $response['html']);
    }

    public function testUnknownTemplateIdsStayAtFourOhFour(): void
    {
        $response = self::fetch('/?swf_template_preview=does-not-exist');
        self::assertSame(404, $response['http']);
        self::assertStringContainsString('This template does not exist.', $response['html']);
    }
}
