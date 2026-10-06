<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use PHPUnit\Framework\TestCase;
use Wildye\EmailParser\Event;
use Wildye\EmailParser\Exception\SignatureVerificationException;
use Wildye\EmailParser\Thread;
use Wildye\EmailParser\Webhook;

/**
 * The fixture is a real event produced and signed by the Node server's own code
 * (src/inbound/parse.ts + src/webhook.ts), so these tests prove the two sides agree.
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_Rml4dHVyZVNlY3JldEZvclNES1Rlc3RzMDE=';
    private const ROTATED = 'whsec_Um90YXRlZEZpeHR1cmVTZWNyZXQwMjM0NTY=';
    private const SIGNED_AT = 1790932497;
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private static function body(): string
    {
        return (string) file_get_contents(self::FIXTURES . '/email.received.json');
    }

    /** Original-format signature header. */
    private static function signature(): string
    {
        return trim((string) file_get_contents(self::FIXTURES . '/email.received.legacy.sig'));
    }

    /** Standard Webhooks headers. @return array<string, string> */
    private static function headers(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/email.received.headers.json'), true);
    }

    private static function event(): Event
    {
        return Webhook::verify(self::body(), self::signature(), self::SECRET, 300, self::SIGNED_AT + 10);
    }

    public function testVerifiesStandardWebhooksHeadersFromTheServer(): void
    {
        $event = Webhook::verifyHeaders(self::body(), self::headers(), self::SECRET, 300, self::SIGNED_AT + 10);
        self::assertSame(self::headers()['webhook-id'], $event->id);
        // Header names in any case, and either secret during a rotation.
        $upper = array_combine(array_map('strtoupper', array_keys(self::headers())), self::headers());
        Webhook::verifyHeaders(self::body(), $upper, self::ROTATED, 300, self::SIGNED_AT + 10);
        // The original format through the same entry point.
        Webhook::verifyHeaders(self::body(), ['Webhook-Signature' => self::signature()], self::SECRET, 300, self::SIGNED_AT + 10);
        $signed = Webhook::signStandard('{"type":"webhook.test","data":{}}', 'evt_1', self::SECRET, 1000);
        self::assertSame('webhook.test', Webhook::verifyHeaders('{"type":"webhook.test","data":{}}', $signed, self::SECRET, 300, 1000)->type);
    }

    /** @dataProvider badStandard */
    public function testRejectsBadStandardSignatures(string $body, array $headers, string $secret, int $now): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::verifyHeaders($body, $headers, $secret, 300, $now);
    }

    public static function badStandard(): array
    {
        $h = self::headers();
        return [
            'wrong secret' => [self::body(), $h, 'whsec_' . base64_encode(random_bytes(24)), self::SIGNED_AT],
            'tampered body' => [str_replace('Order #1042', 'Order #9999', self::body()), $h, self::SECRET, self::SIGNED_AT],
            'replayed with another id' => [self::body(), ['webhook-id' => 'evt_other'] + $h, self::SECRET, self::SIGNED_AT],
            'too old' => [self::body(), $h, self::SECRET, self::SIGNED_AT + 301],
            'no timestamp' => [self::body(), ['webhook-timestamp' => ''] + $h, self::SECRET, self::SIGNED_AT],
        ];
    }

    public function testThread(): void
    {
        $email = self::event()->email();
        self::assertSame(Thread::idFor('<out_1@acme.test>'), $email->threadId);
        self::assertSame(Thread::idFor('out_1@ACME.test'), $email->threadId);
        self::assertTrue($email->isReply());
        self::assertSame(['id' => $email->threadId, 'root_message_id' => '<out_1@acme.test>', 'position' => 1, 'is_reply' => true, 'method' => 'references', 'tag' => 't42'], $email->thread);
    }

    public function testVerifiesASignatureMadeByTheServer(): void
    {
        $event = self::event();
        self::assertSame(Event::EMAIL_RECEIVED, $event->type);
        self::assertTrue($event->isEmailReceived());
        self::assertSame('acct_123', $event->accountId);
        self::assertStringStartsWith('evt_', $event->id);
    }

    public function testParsesTheEmail(): void
    {
        $email = self::event()->email();

        self::assertSame('<reply-1@example.com>', $email->messageId);
        self::assertSame('jane@example.com', $email->from?->address);
        self::assertSame('Jane Doe', $email->from?->name);
        self::assertSame('"Jane Doe" <jane@example.com>', (string) $email->from);
        self::assertSame('example.com', $email->from?->domain());
        self::assertSame(['support@acme.test'], array_map(fn ($a) => $a->address, $email->to));
        self::assertSame('Ops', $email->cc[0]->name);
        self::assertSame('Re: Order #1042', $email->subject);
        self::assertSame('2026-10-01T10:00:00+00:00', $email->date?->format(DATE_ATOM));
        self::assertSame('<out_1@acme.test>', $email->inReplyTo);
        self::assertSame(['<out_1@acme.test>'], $email->references);
        self::assertStringStartsWith('Where is my order? Ünïcödé ✓', (string) $email->text);
        self::assertStringContainsString('> Your order has shipped.', (string) $email->text);
        self::assertSame('Where is my order? Ünïcödé ✓', $email->replyText);
        self::assertSame('<p>Where is my order?</p>', trim((string) $email->html));
        self::assertSame('1042', $email->header('X-Order-Id'));
        self::assertSame(['support+t42.k3j9x2m4q8a7b@acme.test'], $email->recipients());
        self::assertSame('198.51.100.7', $email->envelope['client_ip']);
    }

    public function testAttachments(): void
    {
        $email = self::event()->email();
        self::assertCount(3, $email->attachments);
        [$pdf, $ics] = $email->fileAttachments();
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame('%PDF-1.4 fake invoice', $pdf->content());
        self::assertSame(hash('sha256', '%PDF-1.4 fake invoice'), $pdf->sha256);
        self::assertFalse($pdf->isInline());
        self::assertSame('invite.ics', $ics->filename);
        self::assertTrue($email->attachments[2]->isInline());

        // The sender chose a path-traversal filename; saveTo must keep it inside the directory.
        $dir = sys_get_temp_dir() . '/ep-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $pdf->saveTo($dir);
        self::assertSame($dir . DIRECTORY_SEPARATOR . 'invoice 1042.pdf', $path);
        self::assertSame('%PDF-1.4 fake invoice', file_get_contents($path));
        unlink($path);
        rmdir($dir);
    }

    public function testAuthenticationResults(): void
    {
        $auth = self::event()->email()->authentication;
        self::assertNotNull($auth);
        self::assertTrue($auth->passed());
        self::assertFalse($auth->failed());
        self::assertTrue($auth->dkimPassed());
        self::assertSame('pass', $auth->spfResult());
        self::assertSame('reject', $auth->dmarc['policy']);
    }

    public function testRejectsTheWrongSecret(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::verify(self::body(), self::signature(), 'whsec_wrong', 300, self::SIGNED_AT);
    }

    public function testRejectsATamperedBody(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::verify(str_replace('Order #1042', 'Order #9999', self::body()), self::signature(), self::SECRET, 300, self::SIGNED_AT);
    }

    public function testRejectsOldEvents(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('tolerance');
        Webhook::verify(self::body(), self::signature(), self::SECRET, 300, self::SIGNED_AT + 301);
    }

    /** @dataProvider badHeaders */
    public function testRejectsMalformedHeaders(string $header): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::verify(self::body(), $header, self::SECRET, 0);
    }

    public static function badHeaders(): array
    {
        return ['empty' => [''], 'no timestamp' => ['v1=abc'], 'no signature' => ['t=123'], 'garbage' => ['nonsense']];
    }

    public function testSignRoundTripsAndAcceptsAnyMatchingV1(): void
    {
        $body = '{"id":"evt_x","type":"webhook.test","created_at":"2026-10-02T09:00:00.000Z","tenant_id":"acct_1","data":{"message":"hi"}}';
        $header = Webhook::sign($body, 's3cret', 1000);
        self::assertSame($header, 't=1000,v1=' . hash_hmac('sha256', '1000.' . $body, 's3cret'));
        $event = Webhook::verify($body, 't=1000,v1=deadbeef,' . substr($header, 7), 's3cret', 300, 1000);
        self::assertSame(Event::WEBHOOK_TEST, $event->type);
        self::assertSame(['message' => 'hi'], $event->data);

        $this->expectException(\LogicException::class);
        $event->email();
    }

    public function testVerifiesWithEitherSecretDuringARotation(): void
    {
        self::assertSame(2, substr_count(self::signature(), 'v1='));
        Webhook::verify(self::body(), self::signature(), self::ROTATED, 300, self::SIGNED_AT + 10);
        self::assertSame(Event::EMAIL_RECEIVED, self::event()->type);
        self::assertSame(substr_count(Webhook::sign('x', ['a', 'b'], 5), 'v1='), 2);
    }

    public function testNewFields(): void
    {
        $email = self::event()->email();
        self::assertFalse($email->isAutoSubmitted());
        self::assertNull($email->bounce);
        self::assertSame('support@acme.test', $email->recipient()?->baseAddress);
        self::assertSame('t42', $email->recipient()?->verifiedTag());
        self::assertSame(['support+t42.k3j9x2m4q8a7b@acme.test'], $email->recipients());
        self::assertSame(1.4, $email->spam['score'] ?? null);
        self::assertFalse($email->virus['infected'] ?? true);
        self::assertGreaterThan(500, $email->size);
        self::assertNull($email->rawMessage());
        self::assertSame('rules', $email->extracted['method'] ?? null);
        self::assertSame(1042, $email->field('order_number'));
        self::assertSame(['order_number' => 1042, 'total' => 42.5], $email->fields());
        self::assertNull($email->field('missing'));

        [$call] = $email->calendar;
        self::assertSame('REQUEST', $call->method);
        self::assertSame('Order call', $call->summary);
        self::assertSame('2026-10-05T09:00:00Z', $call->start);
        self::assertSame('jane@example.com', $call->organizer['email'] ?? null);
        self::assertSame('NEEDS-ACTION', $call->attendees[0]['status']);
        self::assertFalse($call->isCancellation());
    }

    /**
     * A multipart/form-data delivery captured from the server. PHP would put the event in $_POST['event'] and the
     * files in $_FILES; here a small parser stands in for PHP's.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}
     */
    private static function multipart(): array
    {
        $meta = json_decode((string) file_get_contents(self::FIXTURES . '/email.multipart.meta.json'), true);
        preg_match('/boundary=(.+)$/', $meta['content_type'], $m);
        $body = (string) file_get_contents(self::FIXTURES . '/email.multipart.body');
        $event = '';
        $files = [];
        foreach (explode('--' . $m[1], $body) as $part) {
            if (!str_contains($part, "\r\n\r\n")) {
                continue;
            }
            [$head, $content] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
            $content = substr($content, 0, -2); // the CRLF before the next boundary
            preg_match('/name="([^"]+)"/', $head, $n);
            if ($n[1] === 'event') {
                $event = $content;
            } else {
                $path = tempnam(sys_get_temp_dir(), 'ep');
                file_put_contents($path, $content);
                $files[$n[1]] = ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
            }
        }

        return [$event, $files, $meta['headers']];
    }

    public function testVerifiesAMultipartDeliveryAndItsFiles(): void
    {
        [$eventJson, $files, $headers] = self::multipart();
        $now = (int) $headers['webhook-timestamp'] + 5;
        $email = Webhook::verifyMultipart($eventJson, $headers, self::SECRET, $files, 300, $now)->email();

        [$pdf] = $email->fileAttachments();
        self::assertSame('attachment_0', $pdf->part);
        self::assertTrue($pdf->hasContent());
        self::assertSame('%PDF-1.4 fake invoice', $pdf->content());
        self::assertStringContainsString('Subject: Re: Order #1042', (string) $email->rawMessage());

        // A swapped file is caught by the signed hash.
        file_put_contents($files['attachment_0']['tmp_name'], 'tampered');
        $tampered = Webhook::verifyMultipart($eventJson, $headers, self::SECRET, $files, 300, $now)->email();
        $this->expectException(SignatureVerificationException::class);
        $tampered->fileAttachments()[0]->content();
    }

    public function testOmittedAttachmentsSayTheyHaveNoContent(): void
    {
        $data = json_decode(self::body(), true);
        $data['data']['attachments'][0]['content'] = null;
        $a = Event::fromArray($data)->email()->attachments[0];
        self::assertFalse($a->hasContent());
        $this->expectException(\LogicException::class);
        $a->content();
    }

    public function testBounces(): void
    {
        $data = json_decode(self::body(), true);
        $data['data']['auto_submitted'] = 'bounce';
        $data['data']['bounce'] = ['recipient' => 'gone@example.com', 'action' => 'failed', 'status' => '5.1.1', 'diagnostic' => '550 User unknown', 'original_message_id' => '<o@x>'];
        $email = Event::fromArray($data)->email();
        self::assertTrue($email->isBounce());
        self::assertTrue($email->bounce?->isPermanent());
        self::assertSame('<o@x>', $email->bounce?->originalMessageId);
    }
}
