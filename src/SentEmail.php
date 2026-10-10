<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** Result of a successful send. Store what you need: the platform doesn't keep a copy. */
final class SentEmail
{
    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    public function __construct(
        public readonly string $id,
        public readonly string $messageId,
        public readonly array $accepted,
        public readonly array $rejected,
        public readonly \DateTimeImmutable $sentAt,
        /** Replies to this email arrive with this threadId. */
        public readonly ?string $threadId = null,
        /** The From address the email was sent with. */
        public readonly ?string $fromAddress = null,
        /** The From name used (yours, or the address's saved From name); null when none. */
        public readonly ?string $fromName = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['message_id'] ?? ''),
            array_values(array_map('strval', $data['accepted'] ?? [])),
            array_values(array_map('strval', $data['rejected'] ?? [])),
            new \DateTimeImmutable((string) ($data['sent_at'] ?? 'now')),
            isset($data['thread_id']) ? (string) $data['thread_id'] : null,
            isset($data['from']['address']) ? (string) $data['from']['address'] : null,
            isset($data['from']['name']) ? (string) $data['from']['name'] : null,
        );
    }
}
