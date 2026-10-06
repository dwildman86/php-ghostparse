<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/** No response from the API (DNS, connection refused, TLS, timeout). Safe to retry: sends use an idempotency key. */
class NetworkException extends EmailParserException
{
}
