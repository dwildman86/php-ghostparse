<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/**
 * Resellers: your customers as sub-accounts (needs reseller features on your account and an API key with the
 * "accounts" permission). Act on one with $client->forAccount($id).
 *
 *     $sub = $client->accounts()->create('Bob\'s Bakery', reference: 'cust-77', webhookUrl: 'https://…');
 *     $secret = $sub['webhook_secret']; // shown once
 *     $client->forAccount($sub['id'])->domains()->create('bobsbakery.com', inboundEnabled: true);
 */
final class Accounts
{
    /** @param \Closure(string, string, ?array, bool): array<string, mixed> $call */
    public function __construct(private readonly \Closure $call)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $reference = null): array
    {
        $query = $reference === null ? '' : '?' . http_build_query(['reference' => $reference]);

        return array_values((array) (($this->call)('GET', '/v1/accounts' . $query, null, true)['accounts'] ?? []));
    }

    /**
     * Create a sub-account. The result includes its webhook_secret, shown only this once.
     * With $ownerEmail, that person is invited (by email) to use the dashboard as the account's owner.
     *
     * @return array<string, mixed>
     */
    public function create(string $name, ?string $reference = null, ?string $webhookUrl = null, ?string $ownerEmail = null): array
    {
        $payload = array_filter(['name' => $name, 'reference' => $reference, 'webhook_url' => $webhookUrl, 'owner_email' => $ownerEmail], static fn ($v) => $v !== null);

        return ($this->call)('POST', '/v1/accounts', $payload, false);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return ($this->call)('GET', '/v1/accounts/' . rawurlencode($id), null, true);
    }

    /**
     * Rename, change your reference, or suspend (mail refused, logins and keys blocked) and unsuspend.
     *
     * @return array<string, mixed>
     */
    public function update(string $id, ?string $name = null, ?string $reference = null, ?bool $suspended = null): array
    {
        $payload = array_filter(['name' => $name, 'reference' => $reference, 'suspended' => $suspended], static fn ($v) => $v !== null);

        return ($this->call)('PATCH', '/v1/accounts/' . rawurlencode($id), $payload, true);
    }

    /** Delete a sub-account with all its domains, keys, users and delivery log. */
    public function delete(string $id): void
    {
        ($this->call)('DELETE', '/v1/accounts/' . rawurlencode($id), null, true);
    }

    /** @return array<string, mixed> */
    public function invite(string $id, string $email, string $role = 'owner'): array
    {
        return ($this->call)('POST', '/v1/accounts/' . rawurlencode($id) . '/invitations', ['email' => $email, 'role' => $role], false);
    }
}
