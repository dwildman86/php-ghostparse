<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use PHPUnit\Framework\TestCase;
use Wildye\EmailParser\Exception\AuthenticationException;
use Wildye\EmailParser\Exception\ConflictException;
use Wildye\EmailParser\Http\Response;
use Wildye\EmailParser\WebmailDataRequests;

final class WebmailDataRequestsTest extends TestCase
{
    private const REQUEST = ['id' => 'dr_abc', 'status' => 'searching', 'email' => 'jane@example.com', 'progress' => ['scanned' => 0, 'total' => 40], 'matches' => 0];

    public function testCreateWaitExportDiscard(): void
    {
        $fake = new FakeTransport(
            FakeTransport::json(201, self::REQUEST),
            FakeTransport::json(409, ['error' => 'not_ready', 'message' => 'Still searching.']),
            FakeTransport::json(200, ['progress' => ['scanned' => 20, 'total' => 40]] + self::REQUEST),
            FakeTransport::json(200, ['status' => 'ready', 'matches' => 1, 'progress' => ['scanned' => 40, 'total' => 40],
                'messages' => [['id' => 7, 'matched_on' => ['from', 'term:Jane Doe']]]] + self::REQUEST),
            new Response(200, "PK\x03\x04", ['content-type' => 'application/zip']),
            FakeTransport::json(200, ['status' => 'expired', 'matches' => null] + self::REQUEST),
            FakeTransport::json(200, ['data_requests' => [self::REQUEST]]),
        );
        $slept = [];
        $dsar = new WebmailDataRequests('https://example.com/mail/', 'wmk_test', ['transport' => $fake, 'sleep' => function (float $s) use (&$slept) { $slept[] = $s; }]);

        $started = $dsar->create('jane@example.com', terms: ['Jane Doe'], since: new \DateTimeImmutable('2025-01-31 10:00'), reference: 'DSAR-42');
        $this->assertSame('searching', $started['status']);
        $this->assertSame('POST', $fake->requests[0]['method']);
        $this->assertSame('https://example.com/mail/api.php?a=data-requests', $fake->requests[0]['url']);
        $this->assertSame('Bearer wmk_test', $fake->requests[0]['headers']['Authorization']);
        $this->assertSame(['email' => 'jane@example.com', 'terms' => ['Jane Doe'], 'since' => '2025-01-31', 'reference' => 'DSAR-42'], json_decode($fake->requests[0]['body'], true));

        try {
            $dsar->export('dr_abc');
            $this->fail('exported while searching');
        } catch (ConflictException $e) {
            $this->assertSame('not_ready', $e->errorCode);
        }
        $seen = [];
        $ready = $dsar->wait('dr_abc', onProgress: function (array $r) use (&$seen) { $seen[] = $r['progress']['scanned']; });
        $this->assertSame([20, 40], $seen);
        $this->assertSame([1.0], $slept);
        $this->assertSame(['from', 'term:Jane Doe'], $ready['messages'][0]['matched_on']);

        $path = tempnam(sys_get_temp_dir(), 'dsar');
        $this->assertSame("PK\x03\x04", $dsar->export('dr_abc', $path));
        $this->assertSame("PK\x03\x04", file_get_contents($path));
        unlink($path);
        $this->assertSame('https://example.com/mail/api.php?a=data-requests%2Fexport&id=dr_abc', $fake->requests[4]['url']);
        $this->assertNull($dsar->discard('dr_abc')['matches']);
        $this->assertSame('{}', $fake->requests[5]['body']);
        $this->assertSame(['dr_abc'], array_column($dsar->list(), 'id'));
    }

    public function testKeyHeaderApiPhpAndBadKeys(): void
    {
        $fake = new FakeTransport(FakeTransport::json(200, ['data_requests' => []]), FakeTransport::json(401, ['error' => 'unauthorized', 'message' => 'A valid API key is needed']));
        $dsar = new WebmailDataRequests('https://example.com/mail/api.php', 'wmk_test', ['transport' => $fake, 'key_header' => 'x-api-key']);
        $this->assertSame([], $dsar->list());
        $this->assertSame('https://example.com/mail/api.php?a=data-requests', $fake->requests[0]['url']);
        $this->assertSame('wmk_test', $fake->requests[0]['headers']['X-Api-Key']);
        $this->assertArrayNotHasKey('Authorization', $fake->requests[0]['headers']);
        $this->expectException(AuthenticationException::class);
        $dsar->list();
    }

    public function testOnlyAWebmailKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebmailDataRequests('https://example.com/mail', 'ek_live_x');
    }
}
