<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** An email address allowed on a domain. */
final class DomainAddress
{
    public function __construct(
        public readonly string $id,
        public readonly string $address,
        public readonly string $localPart,
        public readonly bool $canSend,
        public readonly bool $canReceive,
        public readonly \DateTimeImmutable $createdAt,
        /** A pattern ("ticket-*", or "*" for catch-all) rather than one address. */
        public readonly bool $isPattern = false,
        /** This address's own webhook URL; null = the domain's or account's. */
        public readonly ?string $webhookUrl = null,
        /** Only signed reply addresses are accepted. */
        public readonly bool $requireSignedTag = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['address'] ?? ''),
            (string) ($data['local_part'] ?? ''),
            (bool) ($data['can_send'] ?? false),
            (bool) ($data['can_receive'] ?? false),
            new \DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            (bool) ($data['is_pattern'] ?? false),
            isset($data['webhook_url']) ? (string) $data['webhook_url'] : null,
            (bool) ($data['require_signed_tag'] ?? false),
        );
    }
}
