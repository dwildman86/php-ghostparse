<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/** A webhook didn't come from GhostParse, was altered, or is too old. Respond with HTTP 400. */
class SignatureVerificationException extends EmailParserException
{
}
