<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use PHPUnit\Framework\TestCase;
use Wildye\EmailParser\Email;

final class EmailTest extends TestCase
{
    public function testBuildsTheApiPayload(): void
    {
        $email = Email::create()
            ->from('orders@acme.test', 'Acme')
            ->to('a@example.com')
            ->to('b@example.com', 'Bea')
            ->cc('c@example.com')
            ->bcc('audit@acme.test')
            ->replyTo('support@acme.test')
            ->subject('Your receipt')
            ->text('Thanks')
            ->html('<p>Thanks</p>')
            ->header('X-Order-Id', '1042')
            ->attach('hello', 'note.txt', 'text/plain')
            ->inReplyTo('<abc@example.com>');

        self::assertSame([
            'from' => ['address' => 'orders@acme.test', 'name' => 'Acme'],
            'subject' => 'Your receipt',
            'to' => [['address' => 'a@example.com'], ['address' => 'b@example.com', 'name' => 'Bea']],
            'cc' => [['address' => 'c@example.com']],
            'bcc' => [['address' => 'audit@acme.test']],
            'reply_to' => [['address' => 'support@acme.test']],
            'text' => 'Thanks',
            'html' => '<p>Thanks</p>',
            'in_reply_to' => '<abc@example.com>',
            'references' => ['<abc@example.com>'],
            'headers' => ['X-Order-Id' => '1042'],
            'attachments' => [['filename' => 'note.txt', 'content' => base64_encode('hello'), 'content_type' => 'text/plain']],
        ], $email->toArray());
    }

    public function testAttachFileAndEmbed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ep');
        file_put_contents($path, "col1,col2\n1,2\n");
        $email = Email::create()->from('a@acme.test')->to('b@example.com')->subject('Report')
            ->attachFile($path, 'report.csv', 'text/csv');
        $cid = $email->embed('PNG', 'logo.png', 'image/png', 'logo@acme');
        $email->html("<img src=\"{$cid}\">");
        unlink($path);

        $payload = $email->toArray();
        self::assertSame('cid:logo@acme', $cid);
        self::assertSame("col1,col2\n1,2\n", base64_decode($payload['attachments'][0]['content']));
        self::assertSame(['filename' => 'logo.png', 'content' => base64_encode('PNG'), 'content_type' => 'image/png', 'content_id' => 'logo@acme'], $payload['attachments'][1]);
    }

    public function testRejectsBadInputEarly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Email::create()->to('not-an-email');
    }

    /** @dataProvider incompleteEmails */
    public function testRequiresTheEssentials(Email $email, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $email->toArray();
    }

    public static function incompleteEmails(): array
    {
        return [
            'no from' => [Email::create()->to('b@example.com')->subject('s')->text('t'), 'from()'],
            'no subject' => [Email::create()->from('a@acme.test')->to('b@example.com')->text('t'), 'subject()'],
            'no body' => [Email::create()->from('a@acme.test')->to('b@example.com')->subject('s'), 'text() and/or html()'],
            'no recipient' => [Email::create()->from('a@acme.test')->subject('s')->text('t'), 'recipient'],
        ];
    }

    public function testMissingAttachmentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Email::create()->attachFile('/does/not/exist.pdf');
    }
}
