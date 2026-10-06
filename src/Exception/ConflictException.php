<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/** 409: already exists ($errorCode "domain_exists" or "address_exists"). */
class ConflictException extends ApiException
{
}
