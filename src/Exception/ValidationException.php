<?php

declare(strict_types=1);

namespace Wildye\EmailParser\Exception;

/** 422: the email payload is invalid. getIssues() lists what's wrong. */
class ValidationException extends ApiException
{
    /**
     * @return list<array{path: string, message: string}>
     */
    public function getIssues(): array
    {
        $issues = [];
        foreach ($this->body['issues'] ?? [] as $issue) {
            $issues[] = [
                'path' => implode('.', array_map('strval', (array) ($issue['path'] ?? []))),
                'message' => (string) ($issue['message'] ?? ''),
            ];
        }

        return $issues;
    }
}
