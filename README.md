# GhostParse PHP SDK

The official PHP client for [GhostParse](https://ghostparse.com), the free transactional email API by
WILDYE LIMITED.

- **Send** transactional email with a fluent builder; safe automatic retries that never send twice.
- **Receive** inbound email: verify webhook signatures (JSON or multipart) and get the parsed email as typed objects:
  attachments, the reply without its quoted history, SPF / DKIM / DMARC, bounces and auto-replies, calendar invites,
  spam and virus results.
- **Signed reply addresses**, the **delivery log**, and the **Domains API** (domains, addresses, sender rules).
- No dependencies beyond PHP 8.1+ with `ext-curl` and `ext-json`.

```bash
composer require wildye/email-parser
```

> Transactional email only (receipts, password resets, notifications, replies). Email marketing breaks the
> [Terms](https://ghostparse.com/terms) and gets accounts closed.

## Sending

> Sending is only available on installations that enable it. On an inbound-only installation (the default), `send()`
> throws a `PolicyException` with `errorCode` `outbound_disabled`; use the receiving and Domains API features below.

Create an API key in the dashboard (**API keys**), and verify your sending domain's SPF, DKIM and DMARC records
(**Domains**).

```php
use Wildye\EmailParser\Client;
use Wildye\EmailParser\Email;

$client = new Client(getenv('EMAIL_PARSER_API_KEY'));

$sent = $client->send(
    Email::create()
        ->from('orders@yourapp.com', 'Your App')
        ->to('customer@example.com', 'Sam Customer')
        ->subject('Your receipt for order #1042')
        ->html('<p>Thanks for your order.</p>')
        ->text('Thanks for your order.')
        ->attachFile('/path/to/receipt-1042.pdf'),
    idempotencyKey: 'order-1042-receipt',
);

// The platform doesn't keep a copy: store what you need.
$sent->id;         // "out_…"
$sent->messageId;  // "<out_…@yourapp.com>", for threading replies
$sent->accepted;   // ["customer@example.com"]
```

### The `Email` builder

| Method | |
|---|---|
| `from($address, $name = null)` | Must be an address with **Send** on, under **Domains → your domain → Email addresses** |
| `to()`, `cc()`, `bcc()`, `replyTo()` | Call repeatedly for several recipients (up to 50 per email) |
| `subject($subject)` | |
| `text($text)`, `html($html)` | At least one is required |
| `attach($bytes, $filename, $contentType = null)` | Attach in-memory content |
| `attachFile($path, $filename = null, $contentType = null)` | Attach a file from disk |
| `embed($bytes, $filename, $contentType)` | Inline image; returns a `cid:` URL for your HTML |
| `header($name, $value)` | Custom headers like `X-Order-Id` (bulk headers such as `List-Unsubscribe` are refused) |
| `inReplyTo($messageId, ...$references)` | Reply within an existing thread |

You can also pass a plain array matching the [API's JSON body](https://ghostparse.com) to `send()`.

### Retries and duplicates

Every send carries an `Idempotency-Key`. Network errors, rate limits and 5xx responses are retried (2 retries by
default, with backoff). Because the key stays the same, a retry never sends the email twice. Pass your own key, tied to
the business event (e.g. `"order-1042-receipt"`), to make your own job retries safe too.

### Errors

Everything extends `Wildye\EmailParser\Exception\EmailParserException`.

| Exception | When | Useful properties |
|---|---|---|
| `ValidationException` (422) | Invalid email or domain input (`errorCode` e.g. `invalid_domain`) | `getIssues()`, `errorCode` |
| `PolicyException` (403) | `outbound_disabled` (the installation is inbound-only), `domain_not_verified`, `sender_not_allowed` (From address not on the domain's allow-list), `insufficient_scope` (key lacks the permission), too many recipients, bulk headers | `errorCode` |
| `RateLimitException` (429) | Too many requests, or the daily sending limit (`daily_limit_reached`) | `errorCode`, `retryAfter` |
| `AuthenticationException` (401) | Missing, wrong or revoked API key | |
| `NotFoundException` (404) | Domain or address not on your account | `errorCode` |
| `ConflictException` (409) | `domain_exists`, `address_exists` | `errorCode` |
| `ApiException` | Any other API error | `status`, `errorCode`, `body` |
| `NetworkException` | No response (DNS, connection, timeout) after retries | |

```php
try {
    $client->send($email);
} catch (PolicyException $e) {
    if ($e->errorCode === 'domain_not_verified') { /* finish DNS setup in the dashboard */ }
} catch (EmailParserException $e) {
    report($e);
}
```

### Options

```php
$client = new Client($apiKey, [
    'base_url'    => 'https://ghostparse.com', // your installation
    'timeout'     => 30,   // seconds per request
    'max_retries' => 2,
]);
```

## Domains API (for hosting companies and platforms)

Set up email for your own customers automatically. Needs an API key with the **Manage domains & addresses**
permission (tick it when creating the key).

```php
$domains = $client->domains();

// A customer signs up: add their domain and mailboxes in one call (all-or-nothing).
$domain = $domains->create('customer-shop.com',
    reference: 'customer-1001',                         // your own ID, to find it later
    inboundEnabled: true,
    webhookUrl: 'https://customer-shop.com/webhooks/email', // optional: their own endpoint for inbound mail
    addresses: [
        ['local_part' => 'info'],                       // receive (+ send, where sending is enabled)
        ['local_part' => 'ticket-*'],                   // a pattern: ticket-1@, ticket-2@… ("*" alone = catch-all)
        ['local_part' => 'invoices', 'webhook_url' => 'https://customer-shop.com/webhooks/invoices'], // its own webhook
    ],
    maxMessageBytes: 10 * 1024 * 1024,                  // optional: refuse anything bigger
);

// Publish the records at the customer's DNS (your DNS API here)…
foreach ($domain->records as $r) {
    $dns->upsert($r->type, $r->host, $r->value, $r->priority);
}

// …then verify. DNS can take minutes to propagate: retry with backoff, e.g. from a queued job.
$result = $domains->verify($domain->id);
if ($result->missing() !== []) { /* try again later: e.g. ['mx'] */ }

// Later
$domain = $domains->findByReference('customer-1001');
$domains->addAddress($domain->id, 'billing', requireSignedTag: true);
$domains->addSenderRule($domain->id, SenderRule::BLOCK, 'spammer.example');   // or ALLOW: only listed, authenticated senders
$domains->update($domain->id, inboundEnabled: false);
$domains->delete($domain->id);   // customer left: sending and receiving stop immediately
```

| Method | Returns |
|---|---|
| `create($name, reference:, inboundEnabled:, webhookUrl:, addresses:, maxMessageBytes:)` | `Domain` |
| `list(reference:, name:)`, `findByReference($ref)` | `Domain[]`, `?Domain` |
| `get($id)` | `Domain` |
| `update($id, inboundEnabled:, webhookUrl:, reference:, maxMessageBytes:)` | `Domain` (pass `''` to clear the webhook URL or reference, `0` for the platform size limit) |
| `verify($id)` | `DomainVerification` (`->domain`, `->checks`, `->passed('dkim')`, `->missing()`) |
| `delete($id)` | |
| `addAddress($domainId, $localPart, canSend:, canReceive:, webhookUrl:, requireSignedTag:)`, `updateAddress(…)`, `removeAddress(…)` | `DomainAddress` |
| `senderRules($domainId)`, `addSenderRule($domainId, $action, $pattern)`, `removeSenderRule($domainId, $ruleId)` | `SenderRule[]`, `SenderRule` |

`Domain` has `id`, `name`, `reference`, `inboundEnabled`, `webhookUrl`, `maxMessageBytes`, `sendingReady`,
`inboundReady`, `records` (`DnsRecord`: `kind`, `type`, `host`, `value`, `priority`, `verified`), `addresses`
(`DomainAddress`: `address`, `localPart`, `isPattern`, `canSend`, `canReceive`, `webhookUrl`, `requireSignedTag`) and
`senderRules`, plus `record('dkim')` and `address('info')` helpers. Creating a domain or an
address isn't retried automatically, so a lost response can't turn into a confusing "already exists". Reads, updates
and verification are retried.

## Receiving inbound email

In the dashboard, add your domain, point its MX record at the platform, add the addresses that should receive (with
**Receive** on), turn on **Inbound**, and set your webhook URL. Every email to those addresses (including sub-addresses
like `support+ticket-42@`) is parsed and POSTed to your URL, signed with your webhook secret. Mail to other addresses
is refused.

```php
use Wildye\EmailParser\Exception\SignatureVerificationException;
use Wildye\EmailParser\Webhook;

try {
    $event = Webhook::fromGlobals(getenv('EMAIL_PARSER_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event->isEmailReceived()) {
    $email = $event->email();

    $email->messageId;                 // de-duplicate on this: the sender may retry
    $email->from?->address;            // "jane@example.com"
    $email->from?->name;               // "Jane Doe"
    $email->to;                        // list of Address
    $email->subject;
    $email->text;
    $email->html;
    $email->replyText;                 // just the new text: quoted history and signature removed
    $email->inReplyTo;                 // the Message-ID they replied to, e.g. one of your $sent->messageId
    $email->header('X-Order-Id');

    if ($email->isAutoSubmitted()) {
        // Out-of-office, bounce or other robot mail: don't auto-reply.
        if ($email->isBounce() && $email->bounce?->isPermanent()) {
            // stop emailing $email->bounce->recipient; $email->bounce->originalMessageId is the email that failed
        }
    }

    $ticketId = $email->recipient()?->verifiedTag(); // from a signed reply address: see below

    $orderNumber = $email->field('order_number');    // from the address's parser (rules or AI), or null

    foreach ($email->calendar as $invite) {           // meeting invites (.ics)
        // $invite->method ("REQUEST" / "CANCEL" / "REPLY"), ->uid, ->summary, ->start, ->end, ->attendees
    }

    foreach ($email->fileAttachments() as $file) {
        $path = $file->saveTo('/var/app/uploads');   // filename is sanitised
        // or $file->content(), $file->filename, $file->contentType, $file->size
    }

    if ($email->authentication?->failed()) {
        // DMARC failed: the From address is probably forged
    }
}

http_response_code(200);   // any 2xx = received. Do slow work in a queue.
```

`Webhook::fromGlobals()` reads the raw body from `php://input` and the `webhook-id`, `webhook-timestamp` and
`webhook-signature` headers. In a framework, pass them yourself:

```php
$event = Webhook::verifyHeaders($rawBody, $request->headers->all(), $secret);   // any array of headers
```

Webhooks are signed with [Standard Webhooks](https://www.standardwebhooks.com); accounts created before that keep the
original `t=…,v1=…` format until they switch. `verifyHeaders()` and `fromGlobals()` accept both (`verify()` is the
original format only). Verification checks the HMAC-SHA256 signature in constant time and rejects events older than
5 minutes (replays).
Always use the **raw** request body, not re-encoded JSON. After you rotate the secret, webhooks carry two signatures
for 24 hours; verification accepts either, so you can switch secrets without dropping events.

### Attachments as files (multipart)

If the account's attachment setting is **As separate files**, webhooks arrive as `multipart/form-data`: PHP puts the
JSON in `$_POST['event']` and the files in `$_FILES`. `Webhook::fromGlobals()` handles this automatically. In a
framework:

```php
$event = Webhook::verifyMultipart($request->input('event'), $request->headers->all(), $secret, $_FILES);
foreach ($event->email()->fileAttachments() as $file) {
    $file->saveTo('/var/app/uploads');   // checked against the signed SHA-256 first
}
```

The signature covers the `event` part, which lists each file's SHA-256; `content()` and `saveTo()` check the file
against it and throw `SignatureVerificationException` if it was swapped. With **Leave them out**, `hasContent()` is
false. `$email->rawMessage()` returns the original `.eml` when **Include the original message** is on.

### Extracted fields (parsers)

Give an address a parser on the dashboard's **Parsers** page and every email arrives with the fields you defined:

```php
$email->field('order_number');   // 1042, or null if the email didn't contain it
$email->fields();                // ['order_number' => 1042, 'total' => 42.5, ...]
$email->extracted['error'];      // why extraction failed, e.g. the daily AI limit (the email is still delivered)
```

### Instant address and sample emails

```php
$client->inbox();        // "k3j9x2m4q8a7@in.ghostparse.com": receives mail with no DNS setup
$client->sendSample();   // ['to' => …, 'delivered' => true, 'error' => null]: a realistic email to your webhook
```

For local development, `npx @wildye/email-parser-cli listen --forward http://localhost:8000/webhooks/email` streams
real inbound email to your machine, signed, so `Webhook::fromGlobals()` works unchanged.

### Signed reply addresses

```php
// When you email a customer about ticket 42:
$replyTo = $client->replyAddress('support@yourapp.com', 't42');   // "support+t42.k3j9x2m4q8a7b@yourapp.com"

// When they reply:
$ticketId = $event->email()->recipient()?->verifiedTag();          // "t42", or null if missing or forged
```

Tick **Signed tags only** on the address in the dashboard (or `requireSignedTag: true` via the Domains API) to refuse
every other sub-address.

### Delivery log

```php
$page = $client->deliveries(status: Delivery::REJECTED, limit: 50);
foreach ($page['deliveries'] as $d) {
    // $d->status, $d->reason, $d->recipients, $d->senderDomain, $d->messageId, $d->httpStatus, $d->attempts …
}
$older = $client->deliveries(before: $page['nextBefore']);
```

Metadata only: what happened to each inbound email (delivered, retrying, failed, refused and why, dropped).

### `Authentication` (SPF / DKIM / DMARC)

| | |
|---|---|
| `verdict` | DMARC result for the From domain: `pass`, `fail`, `none`, `temperror`, `permerror` |
| `passed()` / `failed()` | `verdict === 'pass'` / `'fail'` |
| `dkimPassed()` | An aligned DKIM signature verified |
| `spfResult()` | `pass`, `fail`, `softfail`… or `null` if not checked |
| `spf`, `dkim`, `dmarc`, `header` | Full details |

Use these, not any `Authentication-Results` header in `$email->headers`: those come from the sender.

### Testing your endpoint

```php
$client->sendTestWebhook();   // ['delivered' => true, 'event_id' => 'evt_…', 'error' => null]
```

Or sign your own payload in a unit test:

```php
$header = Webhook::sign($json, 'whsec_test');
```

## Laravel

Use the [Laravel package](../laravel) (`composer require wildye/email-parser-laravel`): it registers a verified
webhook route and dispatches `InboundEmailReceived` events. In Symfony, pass `$request->getContent()` and
`$request->headers->all()` to `Webhook::verifyHeaders()`.

## Conversations

`$email->threadId` is the same for every message in a conversation; `$email->thread` has the details (including your
tag from a signed reply address). For an email you sent, `Thread::idFor($messageId)` gives the threadId its replies
will have, and `SentEmail::$threadId` has it when you send through the API.

## Resellers

With reseller features on and a key with the `accounts` permission:

```php
$sub = $client->accounts()->create('Bob\'s Bakery', reference: 'cust-77', webhookUrl: 'https://bobsbakery.example/hook');
$secret = $sub['webhook_secret'];   // shown once

$bob = $client->forAccount($sub['id']);   // acts on the sub-account (Email-Parser-Account header)
$bob->domains()->create('bobsbakery.example', inboundEnabled: true, addresses: [['local_part' => 'orders']]);
$bob->updateWebhookSettings(['backup_webhook_url' => 'https://backup.bobsbakery.example/hook']);
$client->accounts()->update($sub['id'], suspended: true);
```

## Development

```bash
composer install
composer test
```

`tests/fixtures/` holds webhooks produced and signed by the platform's own code (a JSON one signed during a secret
rotation, and a multipart one captured off the wire), so the tests prove the PHP verification matches the server.
Regenerate them with `npx tsx sdk/php/tests/fixtures/generate.mts` from the repository root.
