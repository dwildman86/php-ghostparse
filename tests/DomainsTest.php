<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use PHPUnit\Framework\TestCase;
use Wildye\EmailParser\Client;
use Wildye\EmailParser\SenderRule;
use Wildye\EmailParser\Exception\ConflictException;
use Wildye\EmailParser\Exception\NetworkException;
use Wildye\EmailParser\Exception\NotFoundException;
use Wildye\EmailParser\Exception\PolicyException;
use Wildye\EmailParser\Exception\ValidationException;
use Wildye\EmailParser\Http\Response;

final class DomainsTest extends TestCase
{
    private static function domainJson(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'dom_1', 'name' => 'customer-shop.com', 'reference' => 'cust_1', 'created_at' => '2026-10-02T09:00:00.000Z',
            'last_checked_at' => null, 'inbound_enabled' => true, 'webhook_url' => 'https://customer-shop.com/hook',
            'sending_ready' => false, 'inbound_ready' => false,
            'records' => [
                ['kind' => 'spf', 'type' => 'TXT', 'host' => 'customer-shop.com', 'value' => 'v=spf1 include:_spf.x ~all', 'verified' => false, 'purpose' => 'p', 'note' => 'n'],
                ['kind' => 'mx', 'type' => 'MX', 'host' => 'customer-shop.com', 'value' => 'mx.x', 'priority' => 10, 'verified' => false, 'purpose' => 'p'],
            ],
            'addresses' => [['id' => 'adr_1', 'address' => 'info@customer-shop.com', 'local_part' => 'info', 'can_send' => true, 'can_receive' => true, 'created_at' => '2026-10-02T09:00:00.000Z']],
        ];
    }

    private function client(FakeTransport $t): Client
    {
        return new Client('ek_live_test', ['base_url' => 'https://mail.example', 'transport' => $t, 'sleep' => static function (): void {}]);
    }

    public function testCreateSendsTheFullPayloadAndParsesTheDomain(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::domainJson()));
        $domain = $this->client($t)->domains()->create('customer-shop.com', reference: 'cust_1', inboundEnabled: true,
            webhookUrl: 'https://customer-shop.com/hook', addresses: [['local_part' => 'info'], ['local_part' => 'noreply', 'can_receive' => false]]);

        self::assertSame(['POST', 'https://mail.example/v1/domains'], [$t->requests[0]['method'], $t->requests[0]['url']]);
        self::assertSame([
            'name' => 'customer-shop.com', 'inbound_enabled' => true, 'reference' => 'cust_1', 'webhook_url' => 'https://customer-shop.com/hook',
            'addresses' => [
                ['local_part' => 'info'],
                ['local_part' => 'noreply', 'can_receive' => false],
            ],
        ], json_decode($t->requests[0]['body'], true));

        self::assertSame('dom_1', $domain->id);
        self::assertSame('cust_1', $domain->reference);
        self::assertSame(10, $domain->record('mx')?->priority);
        self::assertNull($domain->record('spf')?->priority);
        self::assertTrue($domain->address('INFO')?->canReceive);
        self::assertNull($domain->lastCheckedAt);
    }

    public function testCreateIsNotRetriedButReadsAre(): void
    {
        $t = new FakeTransport(new NetworkException('reset'));
        try {
            $this->client($t)->domains()->create('x.com');
            self::fail('expected NetworkException');
        } catch (NetworkException) {
            self::assertCount(1, $t->requests);
        }

        $t = new FakeTransport(FakeTransport::json(503, []), FakeTransport::json(200, ['domains' => [self::domainJson()]]));
        $found = $this->client($t)->domains()->findByReference('cust 1');
        self::assertSame('dom_1', $found?->id);
        self::assertCount(2, $t->requests);
        self::assertSame('https://mail.example/v1/domains?reference=cust+1', $t->requests[1]['url']);
        self::assertNull($t->requests[1]['body']);
        self::assertArrayNotHasKey('Content-Type', $t->requests[1]['headers']);
    }

    public function testListFiltersAndEmptyResults(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['domains' => []]), FakeTransport::json(200, ['domains' => []]));
        self::assertSame([], $this->client($t)->domains()->list());
        self::assertNull($this->client($t)->domains()->findByReference('none'));
        self::assertSame('https://mail.example/v1/domains', $t->requests[0]['url']);
    }

    public function testUpdateSendsOnlyWhatChangedAndEmptyStringsClear(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::domainJson(['webhook_url' => null])));
        $domain = $this->client($t)->domains()->update('dom_1', inboundEnabled: false, webhookUrl: '');
        self::assertSame('PATCH', $t->requests[0]['method']);
        self::assertSame(['inbound_enabled' => false, 'webhook_url' => null], json_decode($t->requests[0]['body'], true));
        self::assertNull($domain->webhookUrl);
    }

    public function testVerify(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, [
            'domain' => self::domainJson(['sending_ready' => true]),
            'checks' => ['spf' => ['ok' => true, 'found' => []], 'dkim' => ['ok' => true, 'found' => []], 'dmarc' => ['ok' => true, 'found' => []], 'mx' => ['ok' => false, 'found' => [], 'error' => 'No record found']],
        ]));
        $result = $this->client($t)->domains()->verify('dom_1');
        self::assertSame('https://mail.example/v1/domains/dom_1/verify', $t->requests[0]['url']);
        self::assertTrue($result->domain->sendingReady);
        self::assertTrue($result->passed('dkim'));
        self::assertSame(['mx'], $result->missing());
    }

    public function testAddressesAndDelete(): void
    {
        $address = ['id' => 'adr_2', 'address' => 'billing@customer-shop.com', 'local_part' => 'billing', 'can_send' => true, 'can_receive' => false, 'created_at' => '2026-10-02T09:00:00.000Z'];
        $t = new FakeTransport(
            FakeTransport::json(201, $address),
            FakeTransport::json(200, ['can_receive' => true] + $address),
            new Response(204, ''),
            new Response(204, ''),
        );
        $domains = $this->client($t)->domains();
        self::assertSame('billing@customer-shop.com', $domains->addAddress('dom_1', 'billing', canReceive: false)->address);
        self::assertSame(['local_part' => 'billing', 'can_receive' => false], json_decode($t->requests[0]['body'], true));
        self::assertTrue($domains->updateAddress('dom_1', 'adr_2', canReceive: true)->canReceive);
        self::assertSame(['can_receive' => true], json_decode($t->requests[1]['body'], true));
        $domains->removeAddress('dom_1', 'adr_2');
        $domains->delete('dom_1');
        self::assertSame(['DELETE', 'https://mail.example/v1/domains/dom_1/addresses/adr_2', null], [$t->requests[2]['method'], $t->requests[2]['url'], $t->requests[2]['body']]);
        self::assertSame(['DELETE', 'https://mail.example/v1/domains/dom_1'], [$t->requests[3]['method'], $t->requests[3]['url']]);
    }

    public function testErrorsMapToTypedExceptions(): void
    {
        $cases = [
            [404, ['error' => 'not_found', 'message' => 'Domain not found'], NotFoundException::class],
            [409, ['error' => 'domain_exists', 'message' => 'x is already on this account'], ConflictException::class],
            [403, ['error' => 'insufficient_scope', 'message' => 'needs domains'], PolicyException::class],
            [422, ['error' => 'invalid_domain', 'message' => 'Enter a domain name like example.com'], ValidationException::class],
        ];
        foreach ($cases as [$status, $body, $class]) {
            $t = new FakeTransport(FakeTransport::json($status, $body));
            try {
                $this->client($t)->domains()->get('dom_x');
                self::fail("expected {$class}");
            } catch (\Throwable $e) {
                self::assertInstanceOf($class, $e);
                self::assertSame($body['error'], $e->errorCode);
                self::assertSame($body['message'], $e->getMessage());
            }
        }
    }

    public function testSenderRulesAndAddressOptions(): void
    {
        $rule = ['id' => 'rul_1', 'action' => 'block', 'pattern' => 'spam.example', 'created_at' => '2026-10-02T09:00:00.000Z'];
        $address = ['id' => 'adr_3', 'address' => 'ticket-*@customer-shop.com', 'local_part' => 'ticket-*', 'is_pattern' => true, 'can_send' => false,
            'can_receive' => true, 'webhook_url' => 'https://hooks.example/t', 'require_signed_tag' => true, 'created_at' => '2026-10-02T09:00:00.000Z'];
        $t = new FakeTransport(
            FakeTransport::json(201, $rule),
            FakeTransport::json(200, ['sender_rules' => [$rule]]),
            new Response(204, ''),
            FakeTransport::json(201, $address),
            FakeTransport::json(200, ['webhook_url' => null] + $address),
            FakeTransport::json(200, ['max_message_bytes' => null, 'sender_rules' => [$rule]] + self::domainJson()),
        );
        $domains = $this->client($t)->domains();
        self::assertSame('spam.example', $domains->addSenderRule('dom_1', SenderRule::BLOCK, 'spam.example')->pattern);
        self::assertSame(['action' => 'block', 'pattern' => 'spam.example'], json_decode($t->requests[0]['body'], true));
        self::assertSame('rul_1', $domains->senderRules('dom_1')[0]->id);
        $domains->removeSenderRule('dom_1', 'rul_1');
        self::assertSame(['DELETE', 'https://mail.example/v1/domains/dom_1/sender-rules/rul_1'], [$t->requests[2]['method'], $t->requests[2]['url']]);

        $a = $domains->addAddress('dom_1', 'ticket-*', webhookUrl: 'https://hooks.example/t', requireSignedTag: true);
        self::assertSame(['local_part' => 'ticket-*', 'can_receive' => true, 'webhook_url' => 'https://hooks.example/t', 'require_signed_tag' => true], json_decode($t->requests[3]['body'], true));
        self::assertTrue($a->isPattern && $a->requireSignedTag);
        self::assertNull($domains->updateAddress('dom_1', 'adr_3', webhookUrl: '')->webhookUrl);
        self::assertSame(['webhook_url' => null], json_decode($t->requests[4]['body'], true));

        $d = $domains->update('dom_1', maxMessageBytes: 0);
        self::assertSame(['max_message_bytes' => null], json_decode($t->requests[5]['body'], true));
        self::assertNull($d->maxMessageBytes);
        self::assertSame('block', $d->senderRules[0]->action);
    }
}
