<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** A domain on your account, with the DNS records to publish and its allowed addresses. */
final class Domain
{
    /**
     * @param list<DnsRecord> $records
     * @param list<DomainAddress> $addresses
     * @param list<SenderRule> $senderRules
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** Your own ID for this domain (e.g. a customer number). */
        public readonly ?string $reference,
        public readonly bool $inboundEnabled,
        /** This domain's webhook URL; null means the account's is used. */
        public readonly ?string $webhookUrl,
        /** SPF, DKIM and DMARC verified, and at least one address can send. */
        public readonly bool $sendingReady,
        /** DKIM and MX verified, inbound on, a webhook URL set, and at least one address can receive. */
        public readonly bool $inboundReady,
        public readonly array $records,
        public readonly array $addresses,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastCheckedAt,
        /** Largest email accepted, in bytes; null = the platform limit. */
        public readonly ?int $maxMessageBytes = null,
        public readonly array $senderRules = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            isset($data['reference']) ? (string) $data['reference'] : null,
            (bool) ($data['inbound_enabled'] ?? false),
            isset($data['webhook_url']) ? (string) $data['webhook_url'] : null,
            (bool) ($data['sending_ready'] ?? false),
            (bool) ($data['inbound_ready'] ?? false),
            array_values(array_map(static fn ($r) => DnsRecord::fromArray((array) $r), (array) ($data['records'] ?? []))),
            array_values(array_map(static fn ($a) => DomainAddress::fromArray((array) $a), (array) ($data['addresses'] ?? []))),
            new \DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            isset($data['last_checked_at']) ? new \DateTimeImmutable((string) $data['last_checked_at']) : null,
            isset($data['max_message_bytes']) ? (int) $data['max_message_bytes'] : null,
            array_values(array_map(static fn ($r) => SenderRule::fromArray((array) $r), (array) ($data['sender_rules'] ?? []))),
        );
    }

    public function record(string $kind): ?DnsRecord
    {
        foreach ($this->records as $record) {
            if ($record->kind === $kind) {
                return $record;
            }
        }

        return null;
    }

    public function address(string $localPart): ?DomainAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->localPart === strtolower($localPart)) {
                return $address;
            }
        }

        return null;
    }
}
