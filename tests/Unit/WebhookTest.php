<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\StaticTypeCatalog;
use SWForms\Core\Form\Tree;
use SWForms\Core\Webhook\NetworkGuard;
use SWForms\Core\Webhook\PayloadMapper;
use SWForms\Core\Webhook\RetryPolicy;
use SWForms\Core\Webhook\Signature;

final class WebhookTest extends TestCase
{
    public function testSignatureMatchesKnownVectorAndVerifies(): void
    {
        $body = '{"event":"form.submitted"}';
        $header = Signature::header('s3cret', $body, 1700000000);
        self::assertSame('t=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $body, 's3cret'), $header);
        self::assertTrue(Signature::verify($header, 's3cret', $body, 1700000100));
        self::assertTrue(Signature::verify('t=1700000000,v1=deadbeef,' . substr($header, 13), 's3cret', $body, 1700000000), 'Any v1 may match');
        self::assertFalse(Signature::verify($header, 'other', $body, 1700000000), 'Wrong secret');
        self::assertFalse(Signature::verify($header, 's3cret', $body . ' ', 1700000000), 'Changed body');
        self::assertFalse(Signature::verify($header, 's3cret', $body, 1700000301), 'Replay outside tolerance');
        self::assertFalse(Signature::verify('v1=abc', 's3cret', $body, 1700000000), 'Missing timestamp');
    }

    public function testRetryPolicy(): void
    {
        $policy = new RetryPolicy();
        self::assertSame(6, $policy->maxAttempts());
        self::assertSame(60, $policy->nextDelay(1, null));
        self::assertSame(300, $policy->nextDelay(2, 503));
        self::assertSame(43200, $policy->nextDelay(5, 429));
        self::assertNull($policy->nextDelay(6, 500), 'No retries left');
        self::assertNull($policy->nextDelay(1, 400), 'Client errors are final');
        self::assertNull($policy->nextDelay(1, 404));
        self::assertSame(60, $policy->nextDelay(1, 408));
        self::assertNull((new RetryPolicy([]))->nextDelay(1, 500), 'Retries disabled');
        self::assertTrue(RetryPolicy::isSuccess(204));
        self::assertFalse(RetryPolicy::isSuccess(301));
        self::assertFalse(RetryPolicy::isSuccess(null));
    }

    public function testNetworkGuardBlocksPrivateAndReservedAddresses(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.20.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '64:ff9b::a00:1', '224.0.0.1'] as $ip) {
            self::assertFalse(NetworkGuard::isPublic($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            self::assertTrue(NetworkGuard::isPublic($ip), $ip);
        }
        $guard = new NetworkGuard();
        self::assertSame(NetworkGuard::OK, $guard->check('api.example.org', ['93.184.216.34']));
        self::assertSame(NetworkGuard::PRIVATE_ADDRESS, $guard->check('rebind.example', ['93.184.216.34', '10.0.0.1']), 'Every address must be public');
        self::assertSame(NetworkGuard::UNRESOLVED, $guard->check('nothing.invalid', []));
    }

    public function testNetworkGuardAllowList(): void
    {
        $guard = new NetworkGuard(['erp.local', '*.corp.example', '10.0.5.0/24', '192.168.1.10', 'not a host', '10.0.0.0/99']);
        self::assertSame(NetworkGuard::OK, $guard->check('erp.local', ['10.9.9.9']));
        self::assertSame(NetworkGuard::OK, $guard->check('crm.corp.example', []));
        self::assertSame(NetworkGuard::PRIVATE_ADDRESS, $guard->check('corp.example.evil', ['10.1.1.1']));
        self::assertSame(NetworkGuard::OK, $guard->check('internal', ['10.0.5.77']));
        self::assertSame(NetworkGuard::PRIVATE_ADDRESS, $guard->check('internal', ['10.0.6.1']));
        self::assertSame(NetworkGuard::OK, $guard->check('box', ['192.168.1.10']));
        self::assertSame('10.0.5.0/24', NetworkGuard::normalizeEntry(' 10.0.5.0/24 '));
        self::assertSame('192.168.1.10/32', NetworkGuard::normalizeEntry('192.168.1.10'));
        self::assertSame('*.corp.example', NetworkGuard::normalizeEntry('*.CORP.example'));
        self::assertNull(NetworkGuard::normalizeEntry('http://x'));
        self::assertTrue(NetworkGuard::inRange('fd12::5', 'fd12::/16'));
        self::assertFalse(NetworkGuard::inRange('10.0.0.1', 'fd12::/16'));
    }

    public function testParseUrl(): void
    {
        self::assertSame(['error' => '', 'host' => 'crm.example.org', 'port' => 443], NetworkGuard::parseUrl('https://CRM.example.org/hooks?x=1'));
        self::assertSame(8080, NetworkGuard::parseUrl('http://erp.local:8080/api')['port']);
        self::assertSame('::1', NetworkGuard::parseUrl('http://[::1]/')['host']);
        self::assertSame(NetworkGuard::SCHEME, NetworkGuard::parseUrl('ftp://example.org')['error']);
        self::assertNotSame(NetworkGuard::OK, NetworkGuard::parseUrl('file:///etc/passwd')['error']);
        self::assertSame(NetworkGuard::SCHEME, NetworkGuard::parseUrl('gopher://example.org/')['error']);
        self::assertSame(NetworkGuard::CREDENTIALS, NetworkGuard::parseUrl('https://user:pw@example.org')['error']);
        self::assertSame(NetworkGuard::INVALID_URL, NetworkGuard::parseUrl('example.org/hook')['error']);
    }

    public function testPayloadMapperPicksAndRenamesFields(): void
    {
        $tree = Tree::build([
            ['id' => 'f_mail', 'type' => 'input', 'key' => 'email'],
            ['id' => 'cols', 'type' => 'columns', 'columns' => [
                ['id' => 'c1', 'width' => 12, 'children' => [['id' => 'f_first', 'type' => 'input', 'key' => 'first']]],
            ]],
            ['id' => 'g', 'type' => 'group', 'key' => 'address', 'children' => [
                ['id' => 'f_city', 'type' => 'input', 'key' => 'city'],
            ]],
            ['id' => 'rep', 'type' => 'group', 'key' => 'people', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'f_name', 'type' => 'input', 'key' => 'name'],
            ]],
            ['id' => 'f_hidden', 'type' => 'input', 'key' => 'hidden'],
        ], StaticTypeCatalog::builtIn());
        $data = ['email' => 'a@example.org', 'first' => 'Max', 'address' => ['city' => 'Berlin'], 'people' => [['name' => 'Anna']]];
        $mapper = new PayloadMapper($tree);

        self::assertSame(['address', 'city'], $mapper->keyPath('f_city'));
        self::assertTrue($mapper->isMappable('rep'));
        self::assertFalse($mapper->isMappable('f_name'), 'Inside a repeater');
        self::assertFalse($mapper->isMappable('cols'), 'Layout element');

        self::assertSame(
            ['contact' => ['email' => 'a@example.org', 'first_name' => 'Max'], 'City' => 'Berlin', 'participants' => [['name' => 'Anna']]],
            $mapper->apply($data, [
                ['field' => 'f_mail', 'name' => 'contact.email'],
                ['field' => 'f_first', 'name' => 'contact.first_name'],
                ['field' => 'f_city', 'name' => 'City'],
                ['field' => 'rep', 'name' => 'participants'],
                ['field' => 'f_hidden', 'name' => 'hidden'],
                ['field' => 'f_name', 'name' => 'nope'],
                ['field' => 'f_mail', 'name' => 'bad name'],
            ])
        );
        self::assertTrue(PayloadMapper::validName('00N3000000abc'));
        self::assertTrue(PayloadMapper::validName('lead:source'));
        self::assertFalse(PayloadMapper::validName('a..b'));
        self::assertFalse(PayloadMapper::validName(''));
    }
}
