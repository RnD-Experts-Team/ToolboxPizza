<?php

namespace App\Enums;

/**
 * An explicitly-added participant on one ticket.
 *
 * These are grants made on a single ticket, on top of whoever the section/level
 * resolution already reaches. They exist so a ticket can be shared with someone
 * the routing rules would never pick.
 */
enum TicketParticipantRole: string
{
    /** Can read the ticket and its thread. Nothing else. */
    case Reader = 'reader';

    /** Can read and reply, but not move the ticket - the reporter's powers. */
    case Responder = 'responder';

    /**
     * Full assignee powers on THIS ticket, though the section is not theirs.
     * The escape hatch for "this one needs Dana, even though Dana doesn't own
     * hiring".
     */
    case Assignee = 'assignee';

    public function label(): string
    {
        return match ($this) {
            self::Reader => 'Reader',
            self::Responder => 'Responder',
            self::Assignee => 'Assignee',
        };
    }

    public function canRespond(): bool
    {
        return $this !== self::Reader;
    }

    public function canChangeStatus(): bool
    {
        return $this === self::Assignee;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
