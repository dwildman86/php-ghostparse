<?php

// Receive inbound email. Point your webhook URL at this script, e.g. https://yourapp.com/webhooks/email
//   EMAIL_PARSER_WEBHOOK_SECRET=whsec_... php -S 0.0.0.0:8080 examples/webhook.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Wildye\EmailParser\Exception\SignatureVerificationException;
use Wildye\EmailParser\Webhook;

try {
    $event = Webhook::fromGlobals((string) getenv('EMAIL_PARSER_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400); // not from GhostParse, altered, or too old
    exit;
}

if ($event->isEmailReceived()) {
    $email = $event->email();

    // The sending server may retry, so the same email can arrive twice: skip ones you've already stored.
    // if (alreadyStored($email->messageId)) { http_response_code(200); exit; }

    if ($email->authentication?->failed()) {
        // DMARC failed: the From address is probably forged. Flag it, or drop it.
    }

    $record = [
        'message_id' => $email->messageId,
        'from' => $email->from?->address,
        'to' => array_map(fn ($a) => $a->address, $email->to),
        'subject' => $email->subject,
        'text' => $email->text,
        'html' => $email->html,
        'verified_sender' => $email->authentication?->passed() ?? false,
        'attachments' => [],
    ];
    $dir = getenv('ATTACHMENT_DIR') ?: sys_get_temp_dir();
    foreach ($email->fileAttachments() as $attachment) {
        $record['attachments'][] = $attachment->saveTo($dir); // filename is sanitised
    }

    // Save $record to your database here.
    error_log('Received email: ' . json_encode($record));
    if ($out = getenv('EXAMPLE_OUTPUT_FILE')) {
        file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
    }
}

// Any 2xx tells GhostParse you've got it. Respond quickly; do slow work in a queue.
http_response_code(200);
