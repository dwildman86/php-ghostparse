<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** A verified webhook event. */
final class Event
{
    public const EMAIL_RECEIVED = 'email.received';
    public const WEBHOOK_TEST = 'webhook.test';

    /**
     * @param array<string, mixed> $data Raw event data.
     */
    public function __construct(
        /** Unique per delivery attempt. */
        public readonly string $id,
        public readonly string $type,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $accountId,
        public readonly array $data,
        /** Multipart deliveries: form part name => uploaded file path. */
        private readonly array $files = [],
    ) {
    }

    /**
     * The same event with the files of a multipart delivery attached (part name => path, or PHP's $_FILES).
     * Attachment content is checked against the signed SHA-256 when read.
     *
     * @param array<string, string|array{tmp_name?: string, error?: int}> $files
     */
    public function withFiles(array $files): self
    {
        $paths = [];
        foreach ($files as $part => $file) {
            if (is_string($file)) {
                $paths[(string) $part] = $file;
            } elseif (is_array($file) && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK && isset($file['tmp_name'])) {
                $paths[(string) $part] = (string) $file['tmp_name'];
            }
        }

        return new self($this->id, $this->type, $this->createdAt, $this->accountId, $this->data, $paths);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['id'] ?? ''),
            (string) ($payload['type'] ?? ''),
            new \DateTimeImmutable((string) ($payload['created_at'] ?? 'now')),
            (string) ($payload['tenant_id'] ?? ''),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
        );
    }

    public function isEmailReceived(): bool
    {
        return $this->type === self::EMAIL_RECEIVED;
    }

    /**
     * The parsed email for "email.received" events.
     * The sender may retry delivery, so de-duplicate on $email->messageId.
     */
    public function email(): InboundEmail
    {
        if (!$this->isEmailReceived()) {
            throw new \LogicException("Event {$this->type} has no email");
        }

        return InboundEmail::fromArray($this->data, $this->files);
    }
}
