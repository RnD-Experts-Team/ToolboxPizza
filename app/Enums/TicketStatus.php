<?php

namespace App\Enums;

/**
 * Where a ticket is in its life.
 *
 * Unlike MaintenancePizza's IssueStatus, this enum knows which moves are legal.
 * That enum has no transition map at all and relies on giving some transitions
 * their own endpoint, which means `complete -> pending` is silently accepted
 * there. Four states with one reopen edge is small enough to model properly, so
 * it is modelled.
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Fixed = 'fixed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Fixed => 'Fixed',
            self::Closed => 'Closed',
        };
    }

    /**
     * Work has finished, one way or another. A terminal ticket is not frozen -
     * it can still be reopened - but it is off the queue.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fixed, self::Closed => true,
            self::Pending, self::InProgress => false,
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Someone can start work, resolve it outright, or close it as
            // not-a-problem without ever working it.
            self::Pending => [self::InProgress, self::Fixed, self::Closed],

            self::InProgress => [self::Pending, self::Fixed, self::Closed],

            // Fixed but not yet closed: it can still be closed off, or reopened
            // when the fix did not hold.
            self::Fixed => [self::Closed, self::Pending],

            // Closed is the end of the line. The ONLY way out is a reopen,
            // which lands on Pending - not straight back into progress, because
            // a reopened ticket has to be triaged again.
            self::Closed => [self::Pending],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        // A no-op move is refused rather than silently accepted, so a
        // double-clicked button is visible as TICKET_ALREADY_IN_STATUS instead
        // of writing a second audit row saying nothing changed.
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Coming back out of a terminal state. Recorded distinctly from an ordinary
     * transition because it means something different to the people watching -
     * the notifier branches on it, and it demands a reason.
     */
    public function isReopenEdge(self $to): bool
    {
        return $this->isTerminal() && $to === self::Pending;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
