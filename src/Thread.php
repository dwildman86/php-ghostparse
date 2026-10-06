<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/**
 * Conversation IDs. Every inbound email has a threadId that is the same for the first message of a conversation and
 * every reply to it. Work out the threadId of an email you sent yourself (from its Message-ID) to match replies to it
 * before any arrive:
 *
 *     $ticket->thread_id = Thread::idFor($sent->messageId);
 *     // later, in the webhook:
 *     $ticket = Ticket::where('thread_id', $email->threadId)->first();
 */
final class Thread
{
    public static function idFor(string $messageId): string
    {
        $bare = trim(trim($messageId), '<>');
        $bare = trim($bare);
        $at = strrpos($bare, '@');
        if ($at !== false) {
            $bare = substr($bare, 0, $at) . '@' . strtolower(substr($bare, $at + 1));
        }

        return 'thr_' . substr(hash('sha256', $bare), 0, 24);
    }
}
