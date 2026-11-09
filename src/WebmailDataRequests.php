<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

use Wildye\EmailParser\Exception\ApiException;
use Wildye\EmailParser\Exception\AuthenticationException;
use Wildye\EmailParser\Exception\ConflictException;
use Wildye\EmailParser\Exception\NotFoundException;
use Wildye\EmailParser\Exception\PolicyException;
use Wildye\EmailParser\Exception\RateLimitException;
use Wildye\EmailParser\Exception\ValidationException;
use Wildye\EmailParser\Http\CurlTransport;
use Wildye\EmailParser\Http\Response;
use Wildye\EmailParser\Http\Transport;

/**
 * Data requests (GDPR subject access requests) on a GhostParse webmail (1.12.0 or later): find everything about one
 * person in every mailbox and folder, and export it as a ZIP. Uses a webmail API key (wmk_…) made under
 * Settings → Data requests → From another system, not your GhostParse API key.
 *
 *     $dsar = new WebmailDataRequests('https://example.com/mail', getenv('WEBMAIL_DATA_REQUEST_KEY'));
 *     $request = $dsar->create('jane@example.com', terms: ['Jane Doe'], reference: 'DSAR-42');
 *     $ready = $dsar->wait($request['id']);
 *     $dsar->export($ready['id'], 'dsar-42.zip');   // review it, then send it to Jane
 *     $dsar->discard($ready['id']);                 // once answered: the results go, the record stays
 *
 * Each request is an array: id (dr_…), status ('searching', 'ready' or 'expired'), progress (scanned of total),
 * matches and, once ready, messages (each with matched_on: 'from', 'to', 'subject', 'text', 'term:Jane Doe', …).
 */
final class WebmailDataRequests
{
    private string $endpoint;
    private Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param string $webmailUrl Where the webmail is (https://example.com/mail), or its api.php.
     * @param array{timeout?: float, key_header?: 'authorization'|'x-api-key', transport?: Transport, sleep?: callable(float): void} $options
     *   timeout:    seconds per request (default 120; the export can take a while)
     *   key_header: 'x-api-key' sends the key as X-Api-Key, for hosting that drops the Authorization header
     */
    public function __construct(string $webmailUrl, private readonly string $apiKey, private readonly array $options = [])
    {
        if ($webmailUrl === '') {
            throw new \InvalidArgumentException("The webmail's address is required (such as https://example.com/mail)");
        }
        if (!str_starts_with($apiKey, 'wmk_')) {
            throw new \InvalidArgumentException('A webmail API key (wmk_…) is required: make one under Settings → Data requests → From another system');
        }
        $base = rtrim($webmailUrl, '/');
        $this->endpoint = str_ends_with($base, '/api.php') ? $base : $base . '/api.php';
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * Start a request. The search starts straight away; call get() or wait() for the result.
     *
     * @param list<string> $terms More to look for: their name, a phone number (each at least 3 letters or digits; up to 10).
     * @param list<string> $mailboxes Mailbox addresses to search; all of them when empty.
     * @param \DateTimeInterface|string|null $since Only email from this date ('2025-01-31').
     * @param \DateTimeInterface|string|null $until Only email until this date.
     * @param string|null $reference Yours, such as the ticket number ('DSAR-42').
     * @return array<string, mixed>
     */
    public function create(string $email, array $terms = [], array $mailboxes = [], \DateTimeInterface|string|null $since = null,
        \DateTimeInterface|string|null $until = null, ?string $reference = null, bool $includeAttachments = true): array
    {
        $day = static fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d;
        $body = array_filter(['email' => $email, 'terms' => $terms ?: null, 'mailboxes' => $mailboxes ?: null,
            'since' => $day($since), 'until' => $day($until), 'reference' => $reference], static fn ($v) => $v !== null);
        if (!$includeAttachments) {
            $body['include_attachments'] = false;
        }

        return $this->call('POST', 'data-requests', null, $body)->json();
    }

    /**
     * The request. While it's searching, each call carries the search on (for up to 20 seconds) and reports progress.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->call('GET', 'data-requests', $id)->json();
    }

    /**
     * Call get() until the search has finished. Throws a RuntimeException after $timeout seconds (default 10 minutes).
     *
     * @param (callable(array<string, mixed>): void)|null $onProgress
     * @return array<string, mixed>
     */
    public function wait(string $id, float $timeout = 600.0, float $interval = 1.0, ?callable $onProgress = null): array
    {
        $until = microtime(true) + $timeout;
        while (true) {
            $request = $this->get($id);
            if ($onProgress) {
                $onProgress($request);
            }
            if (($request['status'] ?? '') !== 'searching') {
                return $request;
            }
            if (microtime(true) >= $until) {
                $p = $request['progress'] ?? [];
                throw new \RuntimeException("Data request $id is still searching (" . ($p['scanned'] ?? '?') . ' of ' . ($p['total'] ?? '?') . ' emails checked)');
            }
            ($this->sleep)($interval);
        }
    }

    /**
     * The export: the bytes of a ZIP file, also written to $path if given. ConflictException (not_ready) while it's
     * searching; ApiException 410 (expired) once its results have been removed.
     */
    public function export(string $id, ?string $path = null): string
    {
        $zip = $this->call('GET', 'data-requests/export', $id)->body;
        if ($path !== null && file_put_contents($path, $zip) === false) {
            throw new \RuntimeException("Couldn't write $path");
        }

        return $zip;
    }

    /**
     * Remove the results now (the request has been answered). The record of it stays.
     *
     * @return array<string, mixed>
     */
    public function discard(string $id): array
    {
        return $this->call('POST', 'data-requests/discard', $id, [])->json();
    }

    /**
     * This key's recent requests.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->call('GET', 'data-requests')->json()['data_requests'] ?? [];
    }

    /** @param array<string, mixed>|null $payload */
    private function call(string $method, string $action, ?string $id = null, ?array $payload = null): Response
    {
        $headers = [
            'Accept' => 'application/json, application/zip',
            'User-Agent' => 'email-parser-php/' . Client::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if (strtolower($this->options['key_header'] ?? 'authorization') === 'x-api-key') {
            $headers['X-Api-Key'] = $this->apiKey;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $url = $this->endpoint . '?' . http_build_query(array_filter(['a' => $action, 'id' => $id], static fn ($v) => $v !== null));
        $response = $this->transport->request($method, $url, $headers, $body, (float) ($this->options['timeout'] ?? 120.0));
        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }
        $data = $response->json();
        $code = isset($data['error']) && is_string($data['error']) ? $data['error'] : null;
        $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : "The webmail returned HTTP {$response->status}";

        throw match ($response->status) {
            401 => new AuthenticationException($message, 401, $code, $data),
            403 => new PolicyException($message, 403, $code, $data),
            404 => new NotFoundException($message, 404, $code, $data),
            409 => new ConflictException($message, 409, $code, $data),
            422 => new ValidationException($message, 422, $code, $data),
            429 => new RateLimitException($message, 429, $code, $data, null),
            default => new ApiException($message, $response->status, $code, $data),
        };
    }
}
