<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Http;

use Wildye\EmailParser\Exception\NetworkException;

/**
 * Sends one HTTP request. Swap in your own (e.g. wrapping Guzzle) via the Client constructor,
 * or a fake in tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws NetworkException when no HTTP response was received (DNS, connection, TLS, timeout)
     */
    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
