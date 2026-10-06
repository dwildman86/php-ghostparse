<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Tests;

use Wildye\EmailParser\Exception\NetworkException;
use Wildye\EmailParser\Http\Response;
use Wildye\EmailParser\Http\Transport;

/** Returns queued responses (or throws queued network errors) and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var list<Response|NetworkException> */
    private array $queue;

    public function __construct(Response|NetworkException ...$queue)
    {
        $this->queue = $queue;
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException('No more fake responses queued');
        if ($next instanceof NetworkException) {
            throw $next;
        }

        return $next;
    }

    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }
}
