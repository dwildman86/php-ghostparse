<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** One of your addresses an inbound email was delivered to. */
final class Recipient
{
    public function __construct(
        /** As the sender wrote it, e.g. "support+t42.k3j9x2m4q8a7b@acme.com". */
        public readonly string $address,
        /** The allow-listed address it matched, e.g. "support@acme.com". */
        public readonly string $baseAddress,
        /** The part after "+"; for a signed reply address, just your tag ("t42"). */
        public readonly ?string $tag,
        /** true: a signed reply address your account made. false: signed form, wrong signature. null: not signed. */
        public readonly ?bool $tagVerified,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['address'] ?? ''),
            (string) ($data['base_address'] ?? ''),
            isset($data['tag']) ? (string) $data['tag'] : null,
            isset($data['tag_verified']) ? (bool) $data['tag_verified'] : null,
        );
    }

    /** The tag, but only if it's a genuine signed reply address from your account. */
    public function verifiedTag(): ?string
    {
        return $this->tagVerified === true ? $this->tag : null;
    }
}
