<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Http;

use Wildye\EmailParser\Exception\NetworkException;

final class CurlTransport implements Transport
{
    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) ceil(min($timeout, 10)),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new NetworkException("Request to {$url} failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, (string) $result, $responseHeaders);
    }
}
