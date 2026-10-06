<?php

// Send a transactional email.
//   EMAIL_PARSER_API_KEY=ek_live_... php examples/send.php customer@example.com

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Wildye\EmailParser\Client;
use Wildye\EmailParser\Email;
use Wildye\EmailParser\Exception\EmailParserException;
use Wildye\EmailParser\Exception\PolicyException;
use Wildye\EmailParser\Exception\ValidationException;

$client = new Client((string) getenv('EMAIL_PARSER_API_KEY'), [
    'base_url' => getenv('EMAIL_PARSER_URL') ?: Client::DEFAULT_BASE_URL,
]);

$email = Email::create()
    ->from(getenv('EMAIL_PARSER_FROM') ?: 'orders@yourapp.com', 'Your App')
    ->to($argv[1] ?? 'customer@example.com')
    ->subject('Your receipt for order #1042')
    ->html('<h1>Thanks for your order</h1><p>Order #1042 is confirmed.</p>')
    ->text("Thanks for your order\n\nOrder #1042 is confirmed.")
    ->header('X-Order-Id', '1042')
    ->attach("Order,Total\n1042,£25.00\n", 'receipt-1042.csv', 'text/csv');

try {
    // Use a key tied to the business event, so retrying this script never sends twice.
    $sent = $client->send($email, idempotencyKey: 'order-1042-receipt');
    echo "Sent {$sent->id} ({$sent->messageId}) to " . implode(', ', $sent->accepted) . "\n";
    // Store $sent->id and $sent->messageId: the platform doesn't keep a copy.
} catch (ValidationException $e) {
    echo "Invalid email: {$e->getMessage()}\n";
} catch (PolicyException $e) {
    echo "Not allowed ({$e->errorCode}): {$e->getMessage()}\n";
} catch (EmailParserException $e) {
    echo "Send failed: {$e->getMessage()}\n";
}
