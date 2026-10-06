<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/**
 * 403: the request breaks a platform rule. $errorCode tells you which, e.g.
 * "domain_not_verified" (verify the sender domain's SPF, DKIM and DMARC in the dashboard),
 * "sender_not_allowed" (add the From address under Domains → Email addresses with Send on),
 * "insufficient_scope" (the API key lacks the "send" or "domains" permission)
 * or "policy_violation" (too many recipients, or bulk/marketing headers: the platform is transactional only).
 */
class PolicyException extends ApiException
{
}
