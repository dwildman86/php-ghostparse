<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** One entry in the delivery log: what happened to an inbound email (metadata only). */
final class Delivery
{
    public const DELIVERED = 'delivered';
    public const DEFERRED = 'deferred';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const DROPPED = 'dropped';

    /**
     * @param list<string> $recipients
     * @param array<string, mixed> $flags e.g. dmarc, auto_submitted, virus, signed_tag, code
     */
    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $domainId,
        public readonly ?string $emailId,
        public readonly ?string $eventId,
        public readonly ?string $messageId,
        public readonly ?string $senderDomain,
        public readonly array $recipients,
        public readonly ?string $webhookHost,
        public readonly ?int $httpStatus,
        public readonly ?int $attempts,
        public readonly ?int $durationMs,
        public readonly ?int $sizeBytes,
        public readonly ?float $spamScore,
        public readonly array $flags,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k) => isset($data[$k]) ? (string) $data[$k] : null;
        $i = static fn (string $k) => isset($data[$k]) ? (int) $data[$k] : null;

        return new self(
            (int) ($data['id'] ?? 0), (string) ($data['status'] ?? ''), $s('reason'), $s('domain_id'), $s('email_id'),
            $s('event_id'), $s('message_id'), $s('sender_domain'),
            array_values(array_map('strval', is_array($data['recipients'] ?? null) ? $data['recipients'] : [])),
            $s('webhook_host'), $i('http_status'), $i('attempts'), $i('duration_ms'), $i('size_bytes'),
            isset($data['spam_score']) ? (float) $data['spam_score'] : null,
            is_array($data['flags'] ?? null) ? $data['flags'] : [],
            new \DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
        );
    }
}
