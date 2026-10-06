<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/**
 * Domains API: add your customers' domains and email addresses, get the DNS records to publish,
 * verify them, and route inbound mail. Needs an API key with the "domains" permission.
 *
 *     $domain = $client->domains()->create('customer-shop.com',
 *         reference: 'customer-1001',
 *         inboundEnabled: true,
 *         webhookUrl: 'https://customer-shop.com/webhooks/email',
 *         addresses: [['local_part' => 'info'], ['local_part' => 'noreply', 'can_receive' => false]],
 *     );
 *     foreach ($domain->records as $r) { $dns->upsert($r->type, $r->host, $r->value, $r->priority); }
 *     $result = $client->domains()->verify($domain->id);
 */
final class Domains
{
    /** @param \Closure(string, string, ?array, bool): array<string, mixed> $call */
    public function __construct(private readonly \Closure $call)
    {
    }

    /**
     * Add a domain, optionally with its settings and addresses. All-or-nothing: if anything is invalid, nothing is created.
     *
     * @param list<array{local_part: string, can_send?: bool, can_receive?: bool, webhook_url?: ?string, require_signed_tag?: bool}> $addresses
     *        local_part may be a pattern: "ticket-*", or "*" for a catch-all (receive only).
     * @param int|null $maxMessageBytes Largest email accepted for this domain; null = the platform limit.
     */
    public function create(string $name, ?string $reference = null, bool $inboundEnabled = false, ?string $webhookUrl = null, array $addresses = [], ?int $maxMessageBytes = null): Domain
    {
        $payload = ['name' => $name, 'inbound_enabled' => $inboundEnabled];
        if ($reference !== null) {
            $payload['reference'] = $reference;
        }
        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }
        if ($maxMessageBytes !== null) {
            $payload['max_message_bytes'] = $maxMessageBytes;
        }
        if ($addresses !== []) {
            // Only send what's given: the server's defaults depend on whether the platform sends email.
            $payload['addresses'] = array_map(static fn (array $a) => array_filter([
                'local_part' => $a['local_part'],
                'can_send' => $a['can_send'] ?? null,
                'can_receive' => $a['can_receive'] ?? null,
                'webhook_url' => $a['webhook_url'] ?? null,
                'require_signed_tag' => $a['require_signed_tag'] ?? null,
            ], static fn ($v) => $v !== null), $addresses);
        }

        // Not retried automatically: a retry after a lost response would report "domain_exists".
        return Domain::fromArray(($this->call)('POST', '/v1/domains', $payload, false));
    }

    /** @return list<Domain> */
    public function list(?string $reference = null, ?string $name = null): array
    {
        $query = http_build_query(array_filter(['reference' => $reference, 'name' => $name], static fn ($v) => $v !== null));
        $data = ($this->call)('GET', '/v1/domains' . ($query !== '' ? "?{$query}" : ''), null, true);

        return array_values(array_map(static fn ($d) => Domain::fromArray((array) $d), (array) ($data['domains'] ?? [])));
    }

    /** Find the domain you created with this reference, or null. */
    public function findByReference(string $reference): ?Domain
    {
        return $this->list(reference: $reference)[0] ?? null;
    }

    public function get(string $id): Domain
    {
        return Domain::fromArray(($this->call)('GET', '/v1/domains/' . rawurlencode($id), null, true));
    }

    /**
     * Change settings. Pass only what you want to change. Pass '' for $webhookUrl to fall back to the account's,
     * '' for $reference to clear it, and 0 for $maxMessageBytes to go back to the platform limit.
     */
    public function update(string $id, ?bool $inboundEnabled = null, ?string $webhookUrl = null, ?string $reference = null, ?int $maxMessageBytes = null): Domain
    {
        $payload = array_filter(
            ['inbound_enabled' => $inboundEnabled, 'webhook_url' => $webhookUrl, 'reference' => $reference, 'max_message_bytes' => $maxMessageBytes],
            static fn ($v) => $v !== null,
        );
        foreach (['webhook_url', 'reference'] as $key) {
            if (($payload[$key] ?? null) === '') {
                $payload[$key] = null; // explicit clear
            }
        }
        if (($payload['max_message_bytes'] ?? null) === 0) {
            $payload['max_message_bytes'] = null;
        }

        return Domain::fromArray(($this->call)('PATCH', '/v1/domains/' . rawurlencode($id), $payload, true));
    }

    /** Check DNS now (30 per minute per key). DNS can take minutes to propagate: retry with backoff. */
    public function verify(string $id): DomainVerification
    {
        return DomainVerification::fromArray(($this->call)('POST', '/v1/domains/' . rawurlencode($id) . '/verify', [], true));
    }

    /** Remove the domain and its addresses. Sending and receiving stop immediately. */
    public function delete(string $id): void
    {
        ($this->call)('DELETE', '/v1/domains/' . rawurlencode($id), null, false);
    }

    /**
     * @param string $localPart "support", or a pattern: "ticket-*", or "*" for a catch-all (patterns only receive).
     * @param bool|null $canSend null = the platform default (off on inbound-only installations).
     * @param string|null $webhookUrl Send this address's mail to its own webhook.
     * @param bool $requireSignedTag Only accept signed reply addresses (see Client::replyAddress()).
     */
    public function addAddress(string $domainId, string $localPart, ?bool $canSend = null, bool $canReceive = true, ?string $webhookUrl = null, bool $requireSignedTag = false): DomainAddress
    {
        $payload = array_filter(
            ['local_part' => $localPart, 'can_send' => $canSend, 'can_receive' => $canReceive, 'webhook_url' => $webhookUrl, 'require_signed_tag' => $requireSignedTag ?: null],
            static fn ($v) => $v !== null,
        );

        return DomainAddress::fromArray(($this->call)('POST', '/v1/domains/' . rawurlencode($domainId) . '/addresses', $payload, false));
    }

    /** Pass only what you want to change; '' for $webhookUrl falls back to the domain's or account's. */
    public function updateAddress(string $domainId, string $addressId, ?bool $canSend = null, ?bool $canReceive = null, ?string $webhookUrl = null, ?bool $requireSignedTag = null): DomainAddress
    {
        $payload = array_filter(
            ['can_send' => $canSend, 'can_receive' => $canReceive, 'webhook_url' => $webhookUrl, 'require_signed_tag' => $requireSignedTag],
            static fn ($v) => $v !== null,
        );
        if (($payload['webhook_url'] ?? null) === '') {
            $payload['webhook_url'] = null;
        }

        return DomainAddress::fromArray(($this->call)('PATCH', '/v1/domains/' . rawurlencode($domainId) . '/addresses/' . rawurlencode($addressId), $payload, true));
    }

    public function removeAddress(string $domainId, string $addressId): void
    {
        ($this->call)('DELETE', '/v1/domains/' . rawurlencode($domainId) . '/addresses/' . rawurlencode($addressId), null, false);
    }

    /** @return list<SenderRule> */
    public function senderRules(string $domainId): array
    {
        $data = ($this->call)('GET', '/v1/domains/' . rawurlencode($domainId) . '/sender-rules', null, true);

        return array_values(array_map(static fn ($r) => SenderRule::fromArray((array) $r), (array) ($data['sender_rules'] ?? [])));
    }

    /**
     * @param string $action SenderRule::BLOCK refuses matching senders; any SenderRule::ALLOW rule means only matching,
     *                       authenticated senders are accepted.
     * @param string $pattern "jane@example.com", "example.com" or "*.example.com".
     */
    public function addSenderRule(string $domainId, string $action, string $pattern): SenderRule
    {
        return SenderRule::fromArray(($this->call)('POST', '/v1/domains/' . rawurlencode($domainId) . '/sender-rules', ['action' => $action, 'pattern' => $pattern], false));
    }

    public function removeSenderRule(string $domainId, string $ruleId): void
    {
        ($this->call)('DELETE', '/v1/domains/' . rawurlencode($domainId) . '/sender-rules/' . rawurlencode($ruleId), null, false);
    }
}
