<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

use Wildye\EmailParser\Exception\SignatureVerificationException;

/** A parsed inbound email, as delivered in an "email.received" webhook. */
final class InboundEmail
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $replyTo
     * @param list<string> $references
     * @param array<string, string> $headers Lower-cased header names.
     * @param list<Attachment> $attachments
     * @param array{mail_from: ?string, rcpt_to: list<string>, client_ip: ?string, helo: ?string} $envelope
     * @param list<Recipient> $recipientDetails
     * @param list<CalendarEvent> $calendar
     * @param array{score: float, action: string, symbols: list<string>}|null $spam
     * @param array{infected: bool, names: list<string>}|null $virus
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $messageId,
        public readonly ?Address $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $replyTo,
        public readonly ?string $subject,
        public readonly ?\DateTimeImmutable $date,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly array $headers,
        public readonly array $attachments,
        public readonly ?Authentication $authentication,
        public readonly array $envelope,
        public readonly \DateTimeImmutable $receivedAt,
        /** Just the new part of a reply: quoted history and signature removed (best effort; $text has everything). */
        public readonly ?string $replyText = null,
        /** "auto_reply", "bounce", "auto_generated" or null (sent by a person). Never auto-reply to these. */
        public readonly ?string $autoSubmitted = null,
        public readonly ?Bounce $bounce = null,
        public readonly array $calendar = [],
        /** Your addresses it was delivered to, with sub-address tags (see signed reply addresses). */
        public readonly array $recipientDetails = [],
        /** rspamd result when the platform scans for spam: score (higher = spammier), action, symbols. */
        public readonly ?array $spam = null,
        /** ClamAV result when the platform scans for viruses. */
        public readonly ?array $virus = null,
        public readonly int $size = 0,
        private readonly ?string $rawBase64 = null,
        private readonly ?string $rawPath = null,
        /**
         * What the address's parser pulled out: ['parser_id', 'parser', 'method' => 'rules'|'ai', 'fields' => [...],
         * 'error' => ?string, 'truncated' => ?bool], or null when the address has no parser.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $extracted = null,
        /** The same for every message in a conversation: store it with your ticket or thread (see Thread::idFor). */
        public readonly ?string $threadId = null,
        /**
         * ['id', 'root_message_id', 'position', 'is_reply', 'method', 'tag' => your signed reply tag or null].
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $thread = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $files Multipart deliveries: form part name => path of the uploaded file.
     */
    public static function fromArray(array $data, array $files = []): self
    {
        $list = static fn ($items): array => array_values(array_map(
            static fn ($a) => Address::fromArray((array) $a),
            is_array($items) ? $items : [],
        ));
        $envelope = (array) ($data['envelope'] ?? []);

        return new self(
            (string) ($data['id'] ?? ''),
            isset($data['message_id']) ? (string) $data['message_id'] : null,
            isset($data['from']) && is_array($data['from']) ? Address::fromArray($data['from']) : null,
            $list($data['to'] ?? []),
            $list($data['cc'] ?? []),
            $list($data['reply_to'] ?? []),
            isset($data['subject']) ? (string) $data['subject'] : null,
            isset($data['date']) ? new \DateTimeImmutable((string) $data['date']) : null,
            isset($data['in_reply_to']) ? (string) $data['in_reply_to'] : null,
            array_values(array_map('strval', is_array($data['references'] ?? null) ? $data['references'] : [])),
            isset($data['text']) ? (string) $data['text'] : null,
            isset($data['html']) ? (string) $data['html'] : null,
            array_change_key_case(array_map('strval', is_array($data['headers'] ?? null) ? $data['headers'] : []), CASE_LOWER),
            array_values(array_map(
                static function ($a) use ($files) {
                    $attachment = Attachment::fromArray((array) $a);

                    return $attachment->part !== null && isset($files[$attachment->part]) ? $attachment->withFile($files[$attachment->part]) : $attachment;
                },
                is_array($data['attachments'] ?? null) ? $data['attachments'] : [],
            )),
            isset($data['authentication']) && is_array($data['authentication']) ? Authentication::fromArray($data['authentication']) : null,
            [
                'mail_from' => isset($envelope['mail_from']) ? (string) $envelope['mail_from'] : null,
                'rcpt_to' => array_values(array_map('strval', (array) ($envelope['rcpt_to'] ?? []))),
                'client_ip' => isset($envelope['client_ip']) ? (string) $envelope['client_ip'] : null,
                'helo' => isset($envelope['helo']) ? (string) $envelope['helo'] : null,
            ],
            new \DateTimeImmutable((string) ($data['received_at'] ?? 'now')),
            isset($data['reply_text']) ? (string) $data['reply_text'] : null,
            isset($data['auto_submitted']) ? (string) $data['auto_submitted'] : null,
            is_array($data['bounce'] ?? null) ? Bounce::fromArray($data['bounce']) : null,
            array_values(array_map(static fn ($e) => CalendarEvent::fromArray((array) $e), is_array($data['calendar'] ?? null) ? $data['calendar'] : [])),
            array_values(array_map(static fn ($r) => Recipient::fromArray((array) $r), is_array($data['recipients'] ?? null) ? $data['recipients'] : [])),
            is_array($data['spam'] ?? null) ? [
                'score' => (float) ($data['spam']['score'] ?? 0),
                'action' => (string) ($data['spam']['action'] ?? ''),
                'symbols' => array_values(array_map('strval', (array) ($data['spam']['symbols'] ?? []))),
            ] : null,
            is_array($data['virus'] ?? null) ? [
                'infected' => (bool) ($data['virus']['infected'] ?? false),
                'names' => array_values(array_map('strval', (array) ($data['virus']['names'] ?? []))),
            ] : null,
            (int) ($data['size'] ?? 0),
            isset($data['raw']) ? (string) $data['raw'] : null,
            isset($data['raw_part']) && isset($files[(string) $data['raw_part']]) ? $files[(string) $data['raw_part']] : null,
            is_array($data['extracted'] ?? null) ? $data['extracted'] : null,
            isset($data['thread_id']) ? (string) $data['thread_id'] : null,
            is_array($data['thread'] ?? null) ? $data['thread'] : null,
        );
    }

    /** Is this a reply in an existing conversation (rather than its first message)? */
    public function isReply(): bool
    {
        return (bool) ($this->thread['is_reply'] ?? false);
    }

    /** One extracted field, e.g. $email->field('order_number'); null if missing or there's no parser. */
    public function field(string $name): mixed
    {
        return $this->extracted['fields'][$name] ?? null;
    }

    /** All extracted fields (empty without a parser, or when extraction failed: see $extracted['error']). */
    public function fields(): array
    {
        return is_array($this->extracted['fields'] ?? null) ? $this->extracted['fields'] : [];
    }

    /** Out-of-office reply, bounce or other machine-generated mail. */
    public function isAutoSubmitted(): bool
    {
        return $this->autoSubmitted !== null;
    }

    public function isBounce(): bool
    {
        return $this->autoSubmitted === 'bounce';
    }

    /** The first recipient's details: handy when an email is for one of your addresses. */
    public function recipient(): ?Recipient
    {
        return $this->recipientDetails[0] ?? null;
    }

    /**
     * The original message (.eml), when the account has "Include the original message" on; null otherwise.
     * For multipart deliveries, the raw part isn't covered by the signature: only use it from a verified event.
     */
    public function rawMessage(): ?string
    {
        if ($this->rawBase64 !== null) {
            $decoded = base64_decode($this->rawBase64, true);

            return $decoded === false ? null : $decoded;
        }
        if ($this->rawPath !== null) {
            $bytes = file_get_contents($this->rawPath);
            if ($bytes === false) {
                throw new \RuntimeException("Couldn't read {$this->rawPath}");
            }

            return $bytes;
        }

        return null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The addresses at your domain this message was delivered for (the SMTP envelope, not the To header). */
    public function recipients(): array
    {
        return $this->envelope['rcpt_to'];
    }

    /** @return list<Attachment> Attachments that aren't inline images. */
    public function fileAttachments(): array
    {
        return array_values(array_filter($this->attachments, static fn (Attachment $a) => !$a->isInline()));
    }
}
