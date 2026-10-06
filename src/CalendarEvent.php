<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

/** An event from a calendar invite (.ics) attached to an inbound email. */
final class CalendarEvent
{
    /**
     * @param array{email: string, name: ?string}|null $organizer
     * @param list<array{email: string, name: ?string, status: ?string, role: ?string}> $attendees
     */
    public function __construct(
        /** REQUEST (invite or update), CANCEL, REPLY (an RSVP), PUBLISH. */
        public readonly ?string $method,
        public readonly ?string $uid,
        /** Higher = newer version of the same event. */
        public readonly ?int $sequence,
        public readonly ?string $status,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?string $location,
        /** ISO 8601: ends in "Z" for UTC, otherwise local to $timezone; a date only for all-day events. */
        public readonly ?string $start,
        public readonly ?string $end,
        public readonly ?string $timezone,
        public readonly bool $allDay,
        /** RRULE, e.g. "FREQ=WEEKLY;BYDAY=MO". */
        public readonly ?string $recurrence,
        public readonly ?array $organizer,
        public readonly array $attendees,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k) => isset($data[$k]) ? (string) $data[$k] : null;
        $organizer = is_array($data['organizer'] ?? null)
            ? ['email' => (string) ($data['organizer']['email'] ?? ''), 'name' => isset($data['organizer']['name']) ? (string) $data['organizer']['name'] : null]
            : null;
        $attendees = array_values(array_map(static fn ($a) => [
            'email' => (string) ($a['email'] ?? ''),
            'name' => isset($a['name']) ? (string) $a['name'] : null,
            'status' => isset($a['status']) ? (string) $a['status'] : null,
            'role' => isset($a['role']) ? (string) $a['role'] : null,
        ], is_array($data['attendees'] ?? null) ? $data['attendees'] : []));

        return new self(
            $s('method'), $s('uid'), isset($data['sequence']) ? (int) $data['sequence'] : null, $s('status'),
            $s('summary'), $s('description'), $s('location'), $s('start'), $s('end'), $s('timezone'),
            (bool) ($data['all_day'] ?? false), $s('recurrence'), $organizer, $attendees,
        );
    }

    public function isCancellation(): bool
    {
        return $this->method === 'CANCEL' || $this->status === 'CANCELLED';
    }
}
