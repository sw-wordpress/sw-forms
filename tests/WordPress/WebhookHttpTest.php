<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Webhooks\RequestBuilder;
use SWForms\WordPress\Webhooks\SecretVault;
use SWForms\WordPress\Webhooks\Transport;
use SWForms\WordPress\Webhooks\UrlPolicy;

final class WebhookHttpTest extends TestCase
{
    public function testSafeHttpDeliversSignedBytesToExplicitlyAllowedLocalEchoAndCleansUpFilters(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $url = 'http://' . $address . '/echo';
        $directory = sys_get_temp_dir() . '/swf-webhook-http-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $log = $directory . '/server.log';
        $secret = bin2hex(random_bytes(24));
        $environment = getenv();
        $environment['SWF_WEBHOOK_TEST_SECRET'] = $secret;
        $server = proc_open([PHP_BINARY, '-S', $address, dirname(__DIR__) . '/Support/webhook-echo.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($server);
        $allow = static fn (bool $allowed, string $candidate): bool => $candidate === $url || $allowed;
        $httpFilter = has_filter('http_request_host_is_external');
        $portFilter = has_filter('http_allowed_safe_ports');
        try {
            for ($i = 0; $i < 100; $i++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($connection) {
                    fclose($connection);
                    break;
                }
                usleep(20000);
            }
            $request = (new RequestBuilder())->build(['event' => 'webhook.test', 'eventId' => 'test-1', 'data' => ['name' => 'Mäx & Co']], ['method' => 'PUT', 'format' => 'json', 'headers' => [], 'secretEncrypted' => SecretVault::encrypt($secret)]);
            $request['url'] = $url;
            self::assertFalse((new Transport())->send($request)['success'], 'Loopback remains blocked by default.');
            add_filter('swf_webhook_allow_private_url', $allow, 10, 2);
            $result = (new Transport())->send($request);
            self::assertTrue($result['success'], $result['message']);
            self::assertSame(200, $result['code']);
            $echo = json_decode($result['message'], true);
            self::assertTrue($echo['validSignature']);
            self::assertSame('PUT', $echo['method']);
            self::assertSame('Mäx & Co', $echo['payload']['data']['name']);
            self::assertSame($request['body'], $echo['body']);
            self::assertSame($httpFilter, has_filter('http_request_host_is_external'));
            self::assertSame($portFilter, has_filter('http_allowed_safe_ports'));
            remove_filter('swf_webhook_allow_private_url', $allow);
            self::assertFalse((new Transport())->send($request)['success']);
        } finally {
            remove_filter('swf_webhook_allow_private_url', $allow);
            proc_terminate($server);
            proc_close($server);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            unlink($log);
            rmdir($directory);
        }
    }

    public function testDnsPolicyRejectsMixedAnswersAndReservedAddressEncodings(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1', '198.51.100.7', '203.0.113.9', '224.1.2.3', '255.255.255.255', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::'] as $ip) {
            self::assertFalse(UrlPolicy::public_ip($ip), $ip);
        }
        foreach (['1.1.1.1', '8.8.8.8', '192.0.78.17', '198.51.32.1', '2001:4860:4860::8888', '2606:4700:4700::1111'] as $ip) {
            self::assertTrue(UrlPolicy::public_ip($ip), $ip);
        }
        $policy = new UrlPolicy(static fn () => ['8.8.8.8', '127.0.0.1']);
        $this->expectException(\RuntimeException::class);
        $policy->validate('https://receiver.example/');
    }
}
