<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** Block a sender, or (with any allow rule) accept only listed, authenticated senders, for one domain. */
final class SenderRule
{
    public const ALLOW = 'allow';
    public const BLOCK = 'block';

    public function __construct(
        public readonly string $id,
        public readonly string $action,
        /** "jane@example.com", "example.com" or "*.example.com". */
        public readonly string $pattern,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['id'] ?? ''), (string) ($data['action'] ?? ''), (string) ($data['pattern'] ?? ''));
    }
}
