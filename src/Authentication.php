<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/**
 * SPF, DKIM and DMARC results computed by the platform for an inbound email.
 * Trust these rather than any Authentication-Results header the sender included.
 */
final class Authentication
{
    /**
     * @param array{result: string, domain: ?string, client_ip: ?string}|null $spf
     * @param list<array{result: string, domain: ?string, selector: ?string, aligned: bool}> $dkim
     * @param array{result: string, policy: ?string, domain: ?string}|null $dmarc
     */
    public function __construct(
        /** DMARC result for the From domain: pass, fail, none, temperror or permerror. */
        public readonly string $verdict,
        public readonly ?array $spf,
        public readonly array $dkim,
        public readonly ?array $dmarc,
        public readonly ?string $arcResult,
        /** The Authentication-Results header the platform computed. */
        public readonly string $header,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['verdict'] ?? 'none'),
            isset($data['spf']) && is_array($data['spf']) ? $data['spf'] : null,
            array_values(is_array($data['dkim'] ?? null) ? $data['dkim'] : []),
            isset($data['dmarc']) && is_array($data['dmarc']) ? $data['dmarc'] : null,
            isset($data['arc']['result']) ? (string) $data['arc']['result'] : null,
            (string) ($data['header'] ?? ''),
        );
    }

    /** The sender is who the From header says (DMARC passed). */
    public function passed(): bool
    {
        return $this->verdict === 'pass';
    }

    /** The message failed DMARC: likely spoofed. Only delivered to you if DMARC enforcement is off or the sender's policy isn't "reject". */
    public function failed(): bool
    {
        return $this->verdict === 'fail';
    }

    public function spfResult(): ?string
    {
        return $this->spf['result'] ?? null;
    }

    /** True if at least one DKIM signature aligned with the From domain verified. */
    public function dkimPassed(): bool
    {
        foreach ($this->dkim as $sig) {
            if (($sig['result'] ?? null) === 'pass' && !empty($sig['aligned'])) {
                return true;
            }
        }

        return false;
    }
}
