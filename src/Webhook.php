<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

use Wildye\EmailParser\Exception\SignatureVerificationException;

/**
 * Verify and parse webhooks. Handles both signature formats: Standard Webhooks (webhook-id, webhook-timestamp and
 * "webhook-signature: v1,<base64>", the default) and the original "webhook-signature: t=…,v1=<hex>".
 *
 *     $event = Webhook::fromGlobals(getenv('EMAIL_PARSER_WEBHOOK_SECRET'));
 *     if ($event->isEmailReceived()) {
 *         $email = $event->email();
 *         // save $email->from, $email->subject, $email->text, $email->attachments ...
 *     }
 *     http_response_code(200);
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Webhook-Signature';
    /** Reject events signed more than this many seconds ago (or in the future), to stop replays. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify the signature over the raw request body and return the event.
     *
     * @param string $payload The raw request body, exactly as received (not re-encoded JSON).
     * @param string $signatureHeader The original-format Webhook-Signature header, e.g. "t=1790932497,v1=5d0c…".
     *                                (For Standard Webhooks, use verifyHeaders() or verifyStandard().)
     * @param string $secret Your webhook signing secret (whsec_…) from the dashboard.
     *
     * @throws SignatureVerificationException
     */
    public static function verify(string $payload, string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Webhook secret is empty');
        }
        [$timestamp, $signatures] = self::parseHeader($signatureHeader);
        $now ??= time();
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('Webhook timestamp is outside the allowed tolerance (possible replay)');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (!$valid) {
            throw new SignatureVerificationException('Webhook signature does not match. Check you are using the right secret and the raw request body.');
        }

        return self::decode($payload);
    }

    /** @throws SignatureVerificationException */
    private static function decode(string $payload): Event
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('Webhook body is not valid JSON', 0, $e);
        }
        if (!is_array($data)) {
            throw new SignatureVerificationException('Webhook body is not a JSON object');
        }

        return Event::fromArray($data);
    }

    /**
     * Verify a request from its headers, whichever signature format the account uses.
     *
     * @param string $payload The raw request body (or the "event" part of a multipart delivery).
     * @param array<string, string|list<string>> $headers Request headers; names are matched case-insensitively.
     *
     * @throws SignatureVerificationException
     */
    public static function verifyHeaders(string $payload, array $headers, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $signature = $h['webhook-signature'] ?? '';
        if (str_starts_with(trim($signature), 'v1,')) {
            return self::verifyStandard($payload, $h['webhook-id'] ?? '', $h['webhook-timestamp'] ?? '', $signature, $secret, $tolerance, $now);
        }

        return self::verify($payload, $signature, $secret, $tolerance, $now);
    }

    /**
     * Verify a Standard Webhooks signature (https://www.standardwebhooks.com): HMAC-SHA256 over
     * "{webhook-id}.{webhook-timestamp}.{body}", keyed with the base64-decoded part of the whsec_ secret.
     *
     * @throws SignatureVerificationException
     */
    public static function verifyStandard(string $payload, string $msgId, string $timestamp, string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        if ($msgId === '' || !ctype_digit($timestamp)) {
            throw new SignatureVerificationException('Missing or malformed webhook-id / webhook-timestamp header');
        }
        $now ??= time();
        if ($tolerance > 0 && abs($now - (int) $timestamp) > $tolerance) {
            throw new SignatureVerificationException('Webhook timestamp is outside the allowed tolerance (possible replay)');
        }
        $expected = base64_encode(hash_hmac('sha256', $msgId . '.' . $timestamp . '.' . $payload, self::standardKey($secret), true));
        $valid = false;
        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1') {
                $valid = hash_equals($expected, $signature) || $valid;
            }
        }
        if (!$valid) {
            throw new SignatureVerificationException('Webhook signature does not match. Check you are using the right secret and the raw request body.');
        }

        return self::decode($payload);
    }

    /**
     * Verify the current PHP request (reads php://input and the webhook-* headers).
     * Handles both delivery formats: JSON, and multipart/form-data (where PHP has already parsed the body into
     * $_POST['event'] and $_FILES). On failure it throws; send a 400 response in that case.
     */
    public static function fromGlobals(string $secret, int $tolerance = self::DEFAULT_TOLERANCE): Event
    {
        $headers = [
            'webhook-id' => (string) ($_SERVER['HTTP_WEBHOOK_ID'] ?? ''),
            'webhook-timestamp' => (string) ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? ''),
            'webhook-signature' => (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? ''),
        ];
        if (isset($_POST['event']) && is_string($_POST['event'])) {
            return self::verifyHeaders($_POST['event'], $headers, $secret, $tolerance)->withFiles($_FILES);
        }

        return self::verifyHeaders((string) file_get_contents('php://input'), $headers, $secret, $tolerance);
    }

    /**
     * Verify a multipart/form-data delivery: the signature covers the "event" part, and each file is checked
     * against the SHA-256 listed in the event when you read it.
     *
     * @param string $eventJson The "event" form field, exactly as received.
     * @param array<string, string|array{tmp_name?: string, error?: int}> $files Part name => file path, or PHP's $_FILES.
     *
     * @throws SignatureVerificationException
     */
    public static function verifyMultipart(string $eventJson, string|array $signatureHeader, string $secret, array $files, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        $event = is_array($signatureHeader)
            ? self::verifyHeaders($eventJson, $signatureHeader, $secret, $tolerance, $now)
            : self::verify($eventJson, $signatureHeader, $secret, $tolerance, $now);

        return $event->withFiles($files);
    }

    /**
     * Build a signature header, e.g. to test your own endpoint. Pass several secrets to mimic a rotation in progress
     * (one v1 each, newest first).
     *
     * @param string|list<string> $secret
     */
    public static function sign(string $payload, string|array $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $header = 't=' . $timestamp;
        foreach ((array) $secret as $s) {
            $header .= ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $s);
        }

        return $header;
    }

    /**
     * Build Standard Webhooks headers, e.g. to test your own endpoint.
     *
     * @param string|list<string> $secret
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public static function signStandard(string $payload, string $msgId, string|array $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $signatures = array_map(
            static fn (string $s) => 'v1,' . base64_encode(hash_hmac('sha256', $msgId . '.' . $timestamp . '.' . $payload, self::standardKey($s), true)),
            (array) $secret,
        );

        return ['webhook-id' => $msgId, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => implode(' ', $signatures)];
    }

    private static function standardKey(string $secret): string
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false || $key === '') {
            throw new \InvalidArgumentException('Webhook secret is not a valid Standard Webhooks secret (whsec_ followed by base64)');
        }

        return $key;
    }

    /** @return array{0: int, 1: list<string>} */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1' && $kv[1] !== '') {
                $signatures[] = strtolower($kv[1]);
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('Missing or malformed Webhook-Signature header');
        }

        return [$timestamp, $signatures];
    }
}
