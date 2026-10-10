<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

use Wildye\EmailParser\Exception\ApiException;
use Wildye\EmailParser\Exception\AuthenticationException;
use Wildye\EmailParser\Exception\ConflictException;
use Wildye\EmailParser\Exception\NotFoundException;
use Wildye\EmailParser\Exception\NetworkException;
use Wildye\EmailParser\Exception\PolicyException;
use Wildye\EmailParser\Exception\RateLimitException;
use Wildye\EmailParser\Exception\ValidationException;
use Wildye\EmailParser\Http\CurlTransport;
use Wildye\EmailParser\Http\Response;
use Wildye\EmailParser\Http\Transport;

/**
 * API client.
 *
 *     $client = new Client(getenv('EMAIL_PARSER_API_KEY'));
 *     $sent = $client->send(Email::create()->from(...)->to(...)->subject(...)->html(...));
 *     // store $sent->id / $sent->messageId
 */
final class Client
{
    public const VERSION = '1.2.0';
    public const DEFAULT_BASE_URL = 'https://ghostparse.com';

    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param array{base_url?: string, timeout?: float, max_retries?: int, transport?: Transport, sleep?: callable(float): void} $options
     *   base_url:    your GhostParse installation (default https://ghostparse.com)
     *   timeout:     seconds per request (default 30)
     *   max_retries: retries for network errors, 429 and 5xx (default 2). Sends are idempotent, so retries never duplicate.
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key is required (create one in the dashboard under API keys)');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * Send a transactional email.
     *
     * @param Email|array<string, mixed> $email An Email builder, or the raw JSON body for POST /v1/emails.
     * @param string|null $idempotencyKey Reuse the same key when retrying the same logical email (e.g. "order-1042-receipt")
     *                                    so it's never sent twice. A random one is used if omitted.
     *
     * @throws ValidationException|PolicyException|RateLimitException|AuthenticationException|ApiException|NetworkException
     */
    public function send(Email|array $email, ?string $idempotencyKey = null): SentEmail
    {
        $payload = $email instanceof Email ? $email->toArray() : $email;
        $response = $this->request('POST', '/v1/emails', $payload, $idempotencyKey ?? bin2hex(random_bytes(16)));

        return SentEmail::fromArray($response->json());
    }

    /**
     * Ask the platform to POST a "webhook.test" event to your configured webhook URL.
     *
     * @return array{delivered: bool, event_id: ?string, error: ?string}
     */
    public function sendTestWebhook(): array
    {
        try {
            $data = $this->request('POST', '/v1/webhooks/test', [])->json();
        } catch (ApiException $e) {
            // 502 means "we tried, your endpoint failed": report rather than throw.
            if ($e->status !== 502) {
                throw $e;
            }
            $data = $e->body;
        }

        return [
            'delivered' => (bool) ($data['delivered'] ?? false),
            'event_id' => isset($data['event_id']) ? (string) $data['event_id'] : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * A signed reply address: replyAddress('support@acme.com', 't42') → "support+t42.k3j9x2m4q8a7b@acme.com".
     * Use it as the Reply-To of a notification; replies arrive with $email->recipient()->verifiedTag() === 't42'.
     */
    public function replyAddress(string $address, string $tag): string
    {
        $data = $this->request('POST', '/v1/reply-addresses', ['address' => $address, 'tag' => $tag])->json();

        return (string) ($data['address'] ?? '');
    }

    /**
     * The delivery log: what happened to recent inbound email, newest first. Pass $before = the previous page's
     * nextBefore to page back.
     *
     * @return array{deliveries: list<Delivery>, nextBefore: ?int}
     */
    public function deliveries(?string $status = null, ?string $domainId = null, ?string $messageId = null, ?int $limit = null, ?int $before = null): array
    {
        $query = http_build_query(array_filter(
            ['status' => $status, 'domain_id' => $domainId, 'message_id' => $messageId, 'limit' => $limit, 'before' => $before],
            static fn ($v) => $v !== null,
        ));
        $data = $this->request('GET', '/v1/deliveries' . ($query !== '' ? "?{$query}" : ''), null)->json();

        return [
            'deliveries' => array_values(array_map(static fn ($d) => Delivery::fromArray((array) $d), (array) ($data['deliveries'] ?? []))),
            'nextBefore' => isset($data['next_before']) ? (int) $data['next_before'] : null,
        ];
    }

    /** Your instant inbox address (receives mail without any DNS setup), or null when it's off or unavailable. */
    public function inbox(): ?string
    {
        $data = $this->request('GET', '/v1/inbox', null)->json();

        return isset($data['address']) ? (string) $data['address'] : null;
    }

    /**
     * Send a realistic sample email through the real pipeline to your webhook, to see exactly what you'll receive.
     *
     * @return array{to: string, delivered: bool, error: ?string}
     */
    public function sendSample(?string $to = null): array
    {
        $data = $this->request('POST', '/v1/samples', $to === null ? [] : ['to' => $to])->json();

        return ['to' => (string) ($data['to'] ?? ''), 'delivered' => (bool) ($data['delivered'] ?? false), 'error' => isset($data['error']) ? (string) $data['error'] : null];
    }

    private ?Domains $domains = null;
    private ?Accounts $accounts = null;
    /** Reseller keys: the sub-account this client acts on (Email-Parser-Account header). */
    private ?string $actingAs = null;

    /**
     * A client that acts on one of your sub-accounts (resellers, with a key that has the "accounts" permission):
     * everything it does — domains, webhook settings, deliveries, samples — applies to that sub-account.
     */
    public function forAccount(string $accountId): self
    {
        $clone = clone $this;
        $clone->actingAs = $accountId;
        $clone->domains = null;
        $clone->accounts = null;

        return $clone;
    }

    /** Your sub-accounts (resellers). */
    public function accounts(): Accounts
    {
        return $this->accounts ??= new Accounts(fn (string $method, string $path, ?array $payload, bool $retry) => $this->request($method, $path, $payload, null, $retry)->json());
    }

    /**
     * This billing period's plan and usage: ['plan', 'emails' => ['used', 'included', 'overage'], 'ai' => [...], ...].
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->request('GET', '/v1/usage', null)->json();
    }

    /**
     * Webhook settings: webhook_url, backup_webhook_url, webhook_signature ("standard" or "legacy") and the inbound
     * policies shown on the dashboard's Inbound webhook page.
     *
     * @return array<string, mixed>
     */
    public function webhookSettings(): array
    {
        return $this->request('GET', '/v1/webhook', null)->json();
    }

    /**
     * @param array<string, mixed> $settings Only the keys to change, e.g. ['webhook_url' => 'https://…'].
     * @return array<string, mixed>
     */
    public function updateWebhookSettings(array $settings): array
    {
        return $this->request('PATCH', '/v1/webhook', $settings)->json();
    }

    /**
     * New webhook signing secret. The old one keeps working for 24 hours unless $immediate.
     *
     * @return array{webhook_secret: string, previous_secret_valid_until: ?string}
     */
    public function rotateWebhookSecret(bool $immediate = false): array
    {
        $data = $this->request('POST', '/v1/webhook/secret/rotate', ['immediate' => $immediate], null, false)->json();

        return ['webhook_secret' => (string) ($data['webhook_secret'] ?? ''), 'previous_secret_valid_until' => isset($data['previous_secret_valid_until']) ? (string) $data['previous_secret_valid_until'] : null];
    }

    /**
     * Provision domains and email addresses (needs an API key with the "domains" permission).
     *
     *     $domain = $client->domains()->create('customer-shop.com', addresses: [['local_part' => 'info']]);
     */
    public function domains(): Domains
    {
        return $this->domains ??= new Domains(fn (string $method, string $path, ?array $payload, bool $retry) => $this->request($method, $path, $payload, null, $retry)->json());
    }

    /**
     * @param array<string, mixed>|null $payload JSON body; null sends no body (GET/DELETE).
     * @param bool $retry Retry network errors, 429 and 5xx. Only for requests that are safe to repeat.
     */
    private function request(string $method, string $path, ?array $payload, ?string $idempotencyKey = null, bool $retry = true): Response
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'email-parser-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        if ($this->actingAs !== null) {
            $headers['Email-Parser-Account'] = $this->actingAs;
        }
        $body = $payload === null ? null : json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($body === null) {
            unset($headers['Content-Type']);
        }
        $maxRetries = $retry ? $this->maxRetries : 0;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->request($method, $this->baseUrl . $path, $headers, $body, $this->timeout);
            } catch (NetworkException $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                ($this->sleep)($this->backoff($attempt));
                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }
            $error = $this->toException($response);
            $retryable = $response->status >= 500
                || ($error instanceof RateLimitException && $error->errorCode !== 'daily_limit_reached');
            if (!$retryable || $attempt >= $maxRetries) {
                throw $error;
            }
            $wait = $error instanceof RateLimitException && $error->retryAfter !== null
                ? min((float) $error->retryAfter, 30.0)
                : $this->backoff($attempt);
            ($this->sleep)($wait);
        }
    }

    private function backoff(int $attempt): float
    {
        return 0.5 * (2 ** $attempt);
    }

    private function toException(Response $response): ApiException
    {
        $data = $response->json();
        $code = isset($data['error']) && is_string($data['error']) ? $data['error'] : null;
        $message = isset($data['message']) && is_string($data['message'])
            ? $data['message']
            : "GhostParse API returned HTTP {$response->status}";
        if ($response->status === 422 && !isset($data['message']) && !empty($data['issues'])) {
            $parts = [];
            foreach ($data['issues'] as $issue) {
                $path = implode('.', array_map('strval', (array) ($issue['path'] ?? [])));
                $parts[] = ($path !== '' ? "{$path}: " : '') . ($issue['message'] ?? 'invalid');
            }
            $message = 'Invalid email: ' . implode('; ', $parts);
        }

        return match (true) {
            $response->status === 401 => new AuthenticationException($message, 401, $code, $data),
            $response->status === 422 => new ValidationException($message, 422, $code, $data),
            $response->status === 403 => new PolicyException($message, 403, $code, $data),
            $response->status === 404 => new NotFoundException($message, 404, $code, $data),
            $response->status === 409 => new ConflictException($message, 409, $code, $data),
            $response->status === 429 => new RateLimitException(
                $message,
                429,
                $code,
                $data,
                is_numeric($response->header('retry-after')) ? (int) $response->header('retry-after') : null,
            ),
            default => new ApiException($message, $response->status, $code, $data),
        };
    }
}
