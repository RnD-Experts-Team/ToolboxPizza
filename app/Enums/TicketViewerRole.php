<?php

namespace App\Enums;

/**
 * What one user is to one ticket, once every source of authority has been
 * considered.
 *
 * Resolved by TicketAccessResolver, highest power first, so a reporter who also
 * happens to be an assignee keeps the assignee's powers. Never stored - it is
 * recomputed per request, because assignment itself is dynamic.
 */
enum TicketViewerRole: string
{
    /** Explicitly added to this ticket as an assignee. */
    case ExceptionAssignee = 'exception_assignee';

    /** Reached by the section/level resolution, and has the store. */
    case Assignee = 'assignee';

    /** Raised it. Can follow and reply, but not move it. */
    case Reporter = 'reporter';

    /** Explicitly added, can reply. */
    case Responder = 'responder';

    /** Explicitly added, read-only. */
    case Reader = 'reader';

    /** No relationship at all - answers 404, not 403. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ExceptionAssignee => 'Assignee (added)',
            self::Assignee => 'Assignee',
            self::Reporter => 'Reporter',
            self::Responder => 'Responder',
            self::Reader => 'Reader',
            self::None => 'No access',
        };
    }

    public function canView(): bool
    {
        return $this !== self::None;
    }

    public function canRespond(): bool
    {
        return match ($this) {
            self::ExceptionAssignee, self::Assignee, self::Reporter, self::Responder => true,
            self::Reader, self::None => false,
        };
    }

    /**
     * Moving the ticket, reopening it, and deciding who else is on it are the
     * same authority: ownership of the queue.
     */
    public function canChangeStatus(): bool
    {
        return match ($this) {
            self::ExceptionAssignee, self::Assignee => true,
            self::Reporter, self::Responder, self::Reader, self::None => false,
        };
    }

    public function canManageParticipants(): bool
    {
        return $this->canChangeStatus();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
