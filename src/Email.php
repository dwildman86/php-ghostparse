<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/**
 * Fluent builder for an outbound transactional email.
 *
 *     $email = Email::create()
 *         ->from('orders@yourapp.com', 'Your App')
 *         ->to('customer@example.com')
 *         ->subject('Your receipt')
 *         ->html('<p>Thanks for your order</p>')
 *         ->text('Thanks for your order');
 */
final class Email
{
    /** @var array{address: string, name?: string}|null */
    private ?array $from = null;
    /** @var array<string, list<array{address: string, name?: string}>> */
    private array $recipients = ['to' => [], 'cc' => [], 'bcc' => [], 'reply_to' => []];
    private ?string $subject = null;
    private ?string $text = null;
    private ?string $html = null;
    /** @var array<string, string> */
    private array $headers = [];
    /** @var list<array{filename: string, content: string, content_type?: string, content_id?: string}> */
    private array $attachments = [];
    private ?string $inReplyTo = null;
    /** @var list<string> */
    private array $references = [];

    public static function create(): self
    {
        return new self();
    }

    public function from(string $address, ?string $name = null): self
    {
        $this->from = self::address($address, $name);

        return $this;
    }

    /** Add a recipient. Call repeatedly for more; transactional email is limited to a small number of recipients. */
    public function to(string $address, ?string $name = null): self
    {
        $this->recipients['to'][] = self::address($address, $name);

        return $this;
    }

    public function cc(string $address, ?string $name = null): self
    {
        $this->recipients['cc'][] = self::address($address, $name);

        return $this;
    }

    public function bcc(string $address, ?string $name = null): self
    {
        $this->recipients['bcc'][] = self::address($address, $name);

        return $this;
    }

    public function replyTo(string $address, ?string $name = null): self
    {
        $this->recipients['reply_to'][] = self::address($address, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /**
     * Add a custom header (e.g. X-Order-Id). Headers the API controls itself, and bulk/marketing headers
     * such as List-Unsubscribe or Precedence, are rejected by the API.
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** Attach raw bytes. */
    public function attach(string $content, string $filename, ?string $contentType = null): self
    {
        $attachment = ['filename' => $filename, 'content' => base64_encode($content)];
        if ($contentType !== null) {
            $attachment['content_type'] = $contentType;
        }
        $this->attachments[] = $attachment;

        return $this;
    }

    /** Attach a file from disk. */
    public function attachFile(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException("Can't read attachment file: {$path}");
        }

        return $this->attach($content, $filename ?? basename($path), $contentType ?? self::guessType($path));
    }

    /**
     * Embed an inline image and return the "cid:" URL to use in your HTML:
     *     $cid = $email->embed(file_get_contents('logo.png'), 'logo.png', 'image/png');
     *     $email->html("<img src=\"{$cid}\">");
     */
    public function embed(string $content, string $filename, string $contentType, ?string $contentId = null): string
    {
        $contentId ??= bin2hex(random_bytes(8)) . '@inline';
        $this->attachments[] = [
            'filename' => $filename,
            'content' => base64_encode($content),
            'content_type' => $contentType,
            'content_id' => $contentId,
        ];

        return 'cid:' . $contentId;
    }

    /** Make this a reply in an existing thread: pass the Message-ID you're replying to. */
    public function inReplyTo(string $messageId, string ...$references): self
    {
        $this->inReplyTo = $messageId;
        $this->references = $references === [] ? [$messageId] : array_values($references);

        return $this;
    }

    /** The JSON body for POST /v1/emails. */
    public function toArray(): array
    {
        if ($this->from === null) {
            throw new \LogicException('Email needs a from() address');
        }
        if ($this->subject === null || $this->subject === '') {
            throw new \LogicException('Email needs a subject()');
        }
        if ($this->text === null && $this->html === null) {
            throw new \LogicException('Email needs text() and/or html()');
        }
        if ($this->recipients['to'] === [] && $this->recipients['cc'] === [] && $this->recipients['bcc'] === []) {
            throw new \LogicException('Email needs at least one recipient');
        }

        $payload = ['from' => $this->from, 'subject' => $this->subject];
        foreach ($this->recipients as $field => $list) {
            if ($list !== []) {
                $payload[$field] = $list;
            }
        }
        foreach (['text' => $this->text, 'html' => $this->html, 'in_reply_to' => $this->inReplyTo] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }
        if ($this->references !== []) {
            $payload['references'] = $this->references;
        }
        if ($this->headers !== []) {
            $payload['headers'] = $this->headers;
        }
        if ($this->attachments !== []) {
            $payload['attachments'] = $this->attachments;
        }

        return $payload;
    }

    /** @return array{address: string, name?: string} */
    private static function address(string $address, ?string $name): array
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Invalid email address: {$address}");
        }

        return $name === null || $name === '' ? ['address' => $address] : ['address' => $address, 'name' => $name];
    }

    private static function guessType(string $path): ?string
    {
        if (function_exists('mime_content_type')) {
            $type = @mime_content_type($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return null;
    }
}
