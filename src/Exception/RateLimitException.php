<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/**
 * 429: too many requests per minute, or the account's daily sending limit
 * ($errorCode "daily_limit_reached") was reached.
 */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(string $message, int $status, ?string $errorCode, array $body, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $status, $errorCode, $body);
    }
}
