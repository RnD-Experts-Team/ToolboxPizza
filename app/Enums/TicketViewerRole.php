<?php

namespace App\Enums;

/**
 * What one user is to one ticket, once every source of authority has been
 * considered.
 *
 * Resolved by TicketAccessService, highest power first, so a reporter who also
 * happens to be an assignee keeps the assignee's powers. Never stored - it is
 * recomputed per request, because assignment itself is dynamic.
 */
enum TicketViewerRole: string
{
    /** Explicitly added to this ticket as an assignee. */
    case ExceptionAssignee = 'exception_assignee';

    /** Reached by the section/level resolution, and has the store. */
    case Assignee = 'assignee';

    /**
     * Holds the global `administer tickets` permission (the super-admin does).
     * The same powers as an assignee on EVERY ticket, with no routing needed:
     * whoever runs the ticket system can act on any ticket in it.
     */
    case Administrator = 'administrator';

    /** Raised it. Can follow and reply, but not move it. */
    case Reporter = 'reporter';

    /** Explicitly added, can reply. */
    case Responder = 'responder';

    /** Explicitly added, read-only. */
    case Reader = 'reader';

    /**
     * No relationship to the ticket, but holds `view tickets` globally (the
     * super-admin does). Read-only, and the lowest rung: any real relationship
     * to the ticket outranks it.
     */
    case Viewer = 'viewer';

    /** No relationship at all - answers 404, not 403. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ExceptionAssignee => 'Assignee (added)',
            self::Assignee => 'Assignee',
            self::Administrator => 'Administrator',
            self::Reporter => 'Reporter',
            self::Responder => 'Responder',
            self::Reader => 'Reader',
            self::Viewer => 'Viewer',
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
            self::ExceptionAssignee, self::Assignee, self::Administrator, self::Reporter, self::Responder => true,
            self::Reader, self::Viewer, self::None => false,
        };
    }

    /**
     * Moving the ticket, reopening it, and deciding who else is on it are the
     * same authority: ownership of the queue.
     */
    public function canChangeStatus(): bool
    {
        return match ($this) {
            self::ExceptionAssignee, self::Assignee, self::Administrator => true,
            self::Reporter, self::Responder, self::Reader, self::Viewer, self::None => false,
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
