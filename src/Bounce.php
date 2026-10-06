<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** A delivery failure report (bounce) about an email you sent. */
final class Bounce
{
    public function __construct(
        /** The address that couldn't be delivered to. */
        public readonly ?string $recipient,
        /** "failed", "delayed"… */
        public readonly ?string $action,
        /** Enhanced status code, e.g. "5.1.1": 5.x.x is permanent, 4.x.x temporary. */
        public readonly ?string $status,
        public readonly ?string $diagnostic,
        /** Message-ID of the email that bounced, when the report includes it. */
        public readonly ?string $originalMessageId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k) => isset($data[$k]) ? (string) $data[$k] : null;

        return new self($s('recipient'), $s('action'), $s('status'), $s('diagnostic'), $s('original_message_id'));
    }

    /** Permanent failure (5.x.x): stop emailing this address. */
    public function isPermanent(): bool
    {
        return $this->status !== null ? str_starts_with($this->status, '5') : $this->action === 'failed';
    }
}
