# Changelog

All notable changes to `wildye/email-parser`. Versions follow [semantic versioning](https://semver.org).

## 1.2.0

- `Email::fromName()` sets the From name ("Derek Wildman" in "Derek Wildman <derek@…>"); names with line breaks are
  refused. Without one, the sending address's saved From name is used.
- `Email::signature(false)` leaves the sending address's saved signature out of one email.
- `SentEmail::$fromAddress` and `SentEmail::$fromName`: the From the email was actually sent with.
- Tests run from the package's own repository (fixtures bundled in `tests/fixtures`).

## 1.1.0

- First release on Packagist: sending (`Client::send()`, the `Email` builder), webhook verification (Standard Webhooks
  and the original format), parsed inbound emails, domains, deliveries, sub-accounts and the error classes.
