<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** A DNS record to publish for a domain. */
final class DnsRecord
{
    public function __construct(
        /** spf, dkim, dmarc or mx */
        public readonly string $kind,
        /** TXT or MX */
        public readonly string $type,
        /** Fully-qualified name. Some DNS providers want only the part before the domain. */
        public readonly string $host,
        public readonly string $value,
        public readonly ?int $priority,
        public readonly bool $verified,
        public readonly string $purpose,
        public readonly ?string $note,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['kind'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['host'] ?? ''),
            (string) ($data['value'] ?? ''),
            isset($data['priority']) ? (int) $data['priority'] : null,
            (bool) ($data['verified'] ?? false),
            (string) ($data['purpose'] ?? ''),
            isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
