# Changelog

All notable changes to `wildye/email-parser`. Versions follow [semantic versioning](https://semver.org).

## 1.4.0

- `WebmailDataRequests`: data requests (GDPR subject access requests) on a GhostParse webmail (1.12.0 or later), with a
  webmail API key (`wmk_…`): `create()`, `get()`, `wait()`, `export()` (the ZIP, optionally to a file), `discard()`
  and `list()`.
- `Client::VERSION` is the package's version again (it said 1.2.0 in 1.3.0).

## 1.3.0

- Schema parsers: `InboundEmail::extractedData()` (the answer in your JSON Schema's shape) and
  `InboundEmail::extractedValid()` (whether it passed every check in your schema; reasons in `$extracted['errors']`).
- Attachment storage: `Attachment::$storage` (bucket, key, url, url_expires_at) and `Attachment::storageUrl()` when
  attachments are uploaded to your own bucket instead of sent inline.

## 1.2.0

- `Email::fromName()` sets the From name ("Derek Wildman" in "Derek Wildman <derek@…>"); names with line breaks are
  refused. Without one, the sending address's saved From name is used.
- `Email::signature(false)` leaves the sending address's saved signature out of one email.
- `SentEmail::$fromAddress` and `SentEmail::$fromName`: the From the email was actually sent with.
- Tests run from the package's own repository (fixtures bundled in `tests/fixtures`).

## 1.1.0

- First release on Packagist: sending (`Client::send()`, the `Email` builder), webhook verification (Standard Webhooks
  and the original format), parsed inbound emails, domains, deliveries, sub-accounts and the error classes.
