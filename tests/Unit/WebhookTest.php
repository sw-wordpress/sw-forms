<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\StaticTypeCatalog;
use SWForms\Core\Form\Tree;
use SWForms\Core\Webhooks\Payload;
use SWForms\Core\Webhooks\Signature;

final class WebhookTest extends TestCase
{
    public function testSignatureUsesExactBodyBytesAndKnownHmacVector(): void
    {
        // RFC 4231, test case 2.
        self::assertSame('sha256=5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843', Signature::sign('what do ya want for nothing?', 'Jefe'));
        self::assertNotSame(Signature::sign('{"name":"Max"}', 'secret'), Signature::sign('{ "name":"Max"}', 'secret'));
        self::assertNotSame(Signature::sign('name=Max%20Mustermann', 'secret'), Signature::sign('name=Max+Mustermann', 'secret'));
    }

    public function testEmptySigningSecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signature::sign('{}', '');
    }

    private function tree(string $nameKey = 'name'): Tree
    {
        return Tree::build([
            ['id' => 'layout', 'type' => 'columns', 'columns' => [
                ['id' => 'col', 'children' => [
                    ['id' => 'person', 'type' => 'group', 'key' => 'contact', 'children' => [
                        ['id' => 'name', 'type' => 'input', 'key' => $nameKey],
                        ['id' => 'hidden', 'type' => 'input', 'key' => 'hidden'],
                    ]],
                ]],
            ]],
            ['id' => 'people', 'type' => 'group', 'key' => 'people', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'child', 'type' => 'input', 'key' => 'child'],
            ]],
        ], StaticTypeCatalog::builtIn());
    }

    public function testMappingPreservesFinalDataAndTracksIdsAcrossKeyChanges(): void
    {
        $mapping = [['field' => 'name', 'target' => 'receiver_name'], ['field' => 'people', 'target' => 'attendees'], ['field' => 'hidden', 'target' => 'hidden']];
        $people = [['child' => 'Anna'], ['child' => 'Ben']];
        $data = ['contact' => ['renamed' => 'filtered Max'], 'people' => $people];
        self::assertSame(['receiver_name' => 'filtered Max', 'attendees' => $people], (new Payload($this->tree('renamed')))->map($data, $mapping));
        self::assertSame($data, (new Payload($this->tree()))->map($data, []));
        self::assertSame([], (new Payload($this->tree()))->map($data, [['field' => 'child', 'target' => 'child']]));
    }

    public function testMissingMappedDataDoesNotFallBackToAllFields(): void
    {
        self::assertSame([], (new Payload($this->tree()))->map(['sensitive' => 'value'], [['field' => 'hidden', 'target' => 'missing']]));
    }

    public function testEnvelopeCarriesStableEventIdentityAndNoDocumentSettings(): void
    {
        $payload = Payload::envelope('event-1', 7, 'Contact', 3, 42, '2026-10-29T12:00:00Z', []);
        self::assertSame(['event', 'eventId', 'form', 'entry', 'data'], array_keys($payload));
        self::assertSame('entry.created', $payload['event']);
        self::assertSame(['id' => 7, 'title' => 'Contact', 'version' => 3], $payload['form']);
        self::assertSame('{"event":"entry.created","eventId":"event-1","form":{"id":7,"title":"Contact","version":3},"entry":{"id":42,"date":"2026-10-29T12:00:00Z"},"data":{}}', json_encode($payload));
    }
}
