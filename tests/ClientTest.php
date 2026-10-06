<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use PHPUnit\Framework\TestCase;
use Wildye\EmailParser\Client;
use Wildye\EmailParser\Delivery;
use Wildye\EmailParser\Email;
use Wildye\EmailParser\Exception\ApiException;
use Wildye\EmailParser\Exception\AuthenticationException;
use Wildye\EmailParser\Exception\NetworkException;
use Wildye\EmailParser\Exception\PolicyException;
use Wildye\EmailParser\Exception\RateLimitException;
use Wildye\EmailParser\Exception\ValidationException;

final class ClientTest extends TestCase
{
    /** @var list<float> */
    private array $slept = [];

    private function client(FakeTransport $transport, array $options = []): Client
    {
        return new Client('ek_live_test', $options + [
            'base_url' => 'https://mail.example/',
            'transport' => $transport,
            'sleep' => function (float $s): void {
                $this->slept[] = $s;
            },
        ]);
    }

    private static function email(): Email
    {
        return Email::create()->from('orders@acme.test')->to('c@example.com')->subject('Receipt')->text('Thanks');
    }

    private static function sent(): array
    {
        return ['id' => 'out_1', 'message_id' => '<out_1@acme.test>', 'accepted' => ['c@example.com'], 'rejected' => [], 'sent_at' => '2026-10-02T09:00:00.000Z'];
    }

    public function testSendsAndReturnsTheResult(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::sent()));
        $sent = $this->client($t)->send(self::email());

        self::assertSame('out_1', $sent->id);
        self::assertSame('<out_1@acme.test>', $sent->messageId);
        self::assertSame(['c@example.com'], $sent->accepted);
        self::assertSame('2026-10-02T09:00:00+00:00', $sent->sentAt->format(DATE_ATOM));

        $req = $t->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('https://mail.example/v1/emails', $req['url']);
        self::assertSame('Bearer ek_live_test', $req['headers']['Authorization']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $req['headers']['Idempotency-Key']);
        self::assertSame(self::email()->toArray(), json_decode($req['body'], true));
    }

    public function testAcceptsARawArrayAndAnExplicitIdempotencyKey(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::sent()));
        $this->client($t)->send(['from' => 'a@acme.test', 'to' => 'c@example.com', 'subject' => 's', 'text' => 't'], 'order-1042-receipt');
        self::assertSame('order-1042-receipt', $t->requests[0]['headers']['Idempotency-Key']);
    }

    public function testRetriesNetworkErrorsAnd5xxWithTheSameIdempotencyKey(): void
    {
        $t = new FakeTransport(
            new NetworkException('connection reset'),
            FakeTransport::json(502, ['error' => 'send_failed', 'message' => 'relay down']),
            FakeTransport::json(200, self::sent()),
        );
        $this->client($t)->send(self::email());

        self::assertCount(3, $t->requests);
        self::assertSame(1, count(array_unique(array_map(fn ($r) => $r['headers']['Idempotency-Key'], $t->requests))));
        self::assertSame([0.5, 1.0], $this->slept);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $t = new FakeTransport(new NetworkException('a'), new NetworkException('b'));
        $this->expectException(NetworkException::class);
        $this->client($t, ['max_retries' => 1])->send(self::email());
    }

    public function testValidationErrorsAreReadable(): void
    {
        $t = new FakeTransport(FakeTransport::json(422, ['error' => 'validation_error', 'issues' => [
            ['path' => ['to'], 'message' => 'At least one recipient is required'],
            ['path' => ['attachments', 0, 'content'], 'message' => 'Invalid base64'],
        ]]));
        try {
            $this->client($t)->send(['from' => 'a@acme.test']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('Invalid email: to: At least one recipient is required; attachments.0.content: Invalid base64', $e->getMessage());
            self::assertSame(['path' => 'attachments.0.content', 'message' => 'Invalid base64'], $e->getIssues()[1]);
            self::assertCount(1, $t->requests, 'validation errors are not retried');
        }
    }

    public function testPolicyErrorsCarryTheCode(): void
    {
        $t = new FakeTransport(FakeTransport::json(403, ['error' => 'domain_not_verified', 'message' => 'Sender domain "x.test" is not verified']));
        try {
            $this->client($t)->send(self::email());
            self::fail('expected PolicyException');
        } catch (PolicyException $e) {
            self::assertSame('domain_not_verified', $e->errorCode);
            self::assertSame(403, $e->status);
        }
    }

    public function testDailyLimitIsNotRetriedButRateLimitsAre(): void
    {
        $daily = new FakeTransport(FakeTransport::json(429, ['error' => 'daily_limit_reached', 'message' => 'Free accounts can send 1000 emails per 24 hours']));
        try {
            $this->client($daily)->send(self::email());
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame('daily_limit_reached', $e->errorCode);
            self::assertCount(1, $daily->requests);
        }

        $burst = new FakeTransport(
            FakeTransport::json(429, ['statusCode' => 429, 'error' => 'Too Many Requests', 'message' => 'Rate limit exceeded'], ['retry-after' => '3']),
            FakeTransport::json(200, self::sent()),
        );
        $this->client($burst)->send(self::email());
        self::assertSame([3.0], $this->slept);
    }

    public function testAuthenticationErrors(): void
    {
        $t = new FakeTransport(FakeTransport::json(401, ['statusCode' => 401, 'error' => 'Unauthorized', 'message' => 'Invalid or missing API key']));
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or missing API key');
        $this->client($t)->send(self::email());
    }

    public function testCatchAllBaseException(): void
    {
        $t = new FakeTransport(FakeTransport::json(400, ['error' => 'bad_request']));
        try {
            $this->client($t)->send(self::email());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('GhostParse API returned HTTP 400', $e->getMessage());
        }
    }

    public function testWebhookTestReportsDeliveryFailureWithoutThrowing(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['delivered' => true, 'event_id' => 'evt_1']),
            FakeTransport::json(502, ['delivered' => false, 'event_id' => 'evt_2', 'error' => 'Webhook responded 500']),
        );
        $client = $this->client($t, ['max_retries' => 0]);
        self::assertSame(['delivered' => true, 'event_id' => 'evt_1', 'error' => null], $client->sendTestWebhook());
        self::assertSame(['delivered' => false, 'event_id' => 'evt_2', 'error' => 'Webhook responded 500'], $client->sendTestWebhook());
        self::assertSame('{}', $t->requests[0]['body']);
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testReplyAddressesAndTheDeliveryLog(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['address' => 'support+t42.k3j9x2m4q8a7b@acme.test', 'base_address' => 'support@acme.test', 'tag' => 't42']),
            FakeTransport::json(200, ['deliveries' => [[
                'id' => 9, 'status' => 'deferred', 'reason' => 'Webhook responded 503', 'recipients' => ['support@acme.test'], 'http_status' => 503,
                'attempts' => 3, 'spam_score' => 1.5, 'flags' => ['dmarc' => 'pass'], 'created_at' => '2026-10-02T09:00:00.000Z',
            ]], 'next_before' => 9]),
        );
        $client = new Client('ek_live_test', ['base_url' => 'https://mail.example', 'transport' => $t, 'sleep' => static function (): void {}]);
        self::assertSame('support+t42.k3j9x2m4q8a7b@acme.test', $client->replyAddress('support@acme.test', 't42'));
        self::assertSame(['address' => 'support@acme.test', 'tag' => 't42'], json_decode($t->requests[0]['body'], true));

        $page = $client->deliveries(status: 'deferred', limit: 10);
        self::assertSame('https://mail.example/v1/deliveries?status=deferred&limit=10', $t->requests[1]['url']);
        self::assertSame(9, $page['nextBefore']);
        [$d] = $page['deliveries'];
        self::assertSame([Delivery::DEFERRED, 503, 3, 1.5, ['dmarc' => 'pass']], [$d->status, $d->httpStatus, $d->attempts, $d->spamScore, $d->flags]);
    }

    public function testInboxAndSamples(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['address' => 'k3j9x2m4q8a7@in.example.com', 'enabled' => true, 'available' => true]),
            FakeTransport::json(200, ['to' => 'k3j9x2m4q8a7@in.example.com', 'delivered' => true]),
            FakeTransport::json(200, ['address' => null, 'enabled' => false, 'available' => true]),
        );
        $client = new Client('ek_live_test', ['base_url' => 'https://mail.example', 'transport' => $t, 'sleep' => static function (): void {}]);
        self::assertSame('k3j9x2m4q8a7@in.example.com', $client->inbox());
        self::assertSame(['to' => 'k3j9x2m4q8a7@in.example.com', 'delivered' => true, 'error' => null], $client->sendSample());
        self::assertSame(['POST', 'https://mail.example/v1/samples', '{}'], [$t->requests[1]['method'], $t->requests[1]['url'], $t->requests[1]['body']]);
        self::assertNull($client->inbox());
    }

    public function testResellerActsOnSubAccounts(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(201, ['id' => 'acct_sub', 'name' => 'Bakery', 'webhook_secret' => 'whsec_x']),
            FakeTransport::json(200, ['webhook_url' => 'https://bakery.test/hook', 'webhook_signature' => 'standard']),
            FakeTransport::json(200, ['accounts' => [['id' => 'acct_sub']]]),
        );
        $client = $this->client($t);
        $sub = $client->accounts()->create('Bakery', reference: 'cust-77');
        self::assertSame('whsec_x', $sub['webhook_secret']);
        self::assertSame(['name' => 'Bakery', 'reference' => 'cust-77'], json_decode((string) $t->requests[0]['body'], true));

        $settings = $client->forAccount('acct_sub')->updateWebhookSettings(['webhook_url' => 'https://bakery.test/hook']);
        self::assertSame('standard', $settings['webhook_signature']);
        self::assertSame('PATCH', $t->requests[1]['method']);
        self::assertSame('https://mail.example/v1/webhook', $t->requests[1]['url']);
        self::assertSame('acct_sub', $t->requests[1]['headers']['Email-Parser-Account']);

        // The original client is unchanged.
        $client->accounts()->list();
        self::assertArrayNotHasKey('Email-Parser-Account', $t->requests[2]['headers']);
    }
}
