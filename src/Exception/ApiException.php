<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/** The API answered with an error. Subclasses cover the common cases. */
class ApiException extends EmailParserException
{
    /**
     * @param array<string, mixed> $body Decoded JSON error body.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status);
    }
}
