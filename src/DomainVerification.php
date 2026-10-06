<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** Result of checking a domain's DNS. */
final class DomainVerification
{
    /**
     * @param array<string, array{ok: bool, found: list<string>, error?: string}> $checks Keyed by spf, dkim, dmarc, mx.
     */
    public function __construct(public readonly Domain $domain, public readonly array $checks)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Domain::fromArray((array) ($data['domain'] ?? [])), (array) ($data['checks'] ?? []));
    }

    public function passed(string $kind): bool
    {
        return (bool) ($this->checks[$kind]['ok'] ?? false);
    }

    /** @return list<string> Record kinds that aren't in place yet, e.g. ["dkim", "mx"]. */
    public function missing(): array
    {
        return array_values(array_keys(array_filter($this->checks, static fn ($c) => empty($c['ok']))));
    }
}
