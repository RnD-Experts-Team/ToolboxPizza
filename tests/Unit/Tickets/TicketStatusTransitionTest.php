<?php

namespace Tests\Unit\Tickets;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pure arithmetic over the transition map - no application, no database.
 *
 * MaintenancePizza's IssueStatus has no map at all and accepts
 * `complete -> pending` silently, which is the failure this file exists to make
 * impossible here.
 */
class TicketStatusTransitionTest extends TestCase
{
    public function test_pending_can_start_resolve_or_be_closed_outright(): void
    {
        $this->assertTrue(TicketStatus::Pending->canTransitionTo(TicketStatus::InProgress));
        $this->assertTrue(TicketStatus::Pending->canTransitionTo(TicketStatus::Fixed));
        // Closed without ever being worked: "not a problem" is a real outcome.
        $this->assertTrue(TicketStatus::Pending->canTransitionTo(TicketStatus::Closed));
    }

    public function test_in_progress_can_go_back_to_pending(): void
    {
        // Picked up by mistake, or handed back to the queue.
        $this->assertTrue(TicketStatus::InProgress->canTransitionTo(TicketStatus::Pending));
    }

    public function test_a_closed_ticket_cannot_jump_straight_back_into_progress(): void
    {
        // The only way out of Closed is a reopen, which lands on Pending so the
        // ticket gets triaged again rather than silently resuming.
        $this->assertFalse(TicketStatus::Closed->canTransitionTo(TicketStatus::InProgress));
        $this->assertFalse(TicketStatus::Closed->canTransitionTo(TicketStatus::Fixed));
        $this->assertTrue(TicketStatus::Closed->canTransitionTo(TicketStatus::Pending));
    }

    public function test_a_fixed_ticket_can_be_closed_off_or_reopened(): void
    {
        $this->assertTrue(TicketStatus::Fixed->canTransitionTo(TicketStatus::Closed));
        $this->assertTrue(TicketStatus::Fixed->canTransitionTo(TicketStatus::Pending));
        $this->assertFalse(TicketStatus::Fixed->canTransitionTo(TicketStatus::InProgress));
    }

    /**
     * A double-clicked button must be visible as TICKET_ALREADY_IN_STATUS, not
     * written as a second audit row saying nothing changed.
     */
    public function test_no_status_can_transition_to_itself(): void
    {
        foreach (TicketStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} should not transition to itself",
            );
        }
    }

    public function test_only_fixed_and_closed_are_terminal(): void
    {
        $this->assertTrue(TicketStatus::Fixed->isTerminal());
        $this->assertTrue(TicketStatus::Closed->isTerminal());
        $this->assertFalse(TicketStatus::Pending->isTerminal());
        $this->assertFalse(TicketStatus::InProgress->isTerminal());
    }

    public function test_the_reopen_edge_is_only_out_of_a_terminal_state(): void
    {
        $this->assertTrue(TicketStatus::Fixed->isReopenEdge(TicketStatus::Pending));
        $this->assertTrue(TicketStatus::Closed->isReopenEdge(TicketStatus::Pending));

        // Handing an in-progress ticket back to the queue is an ordinary move,
        // not a reopen - it demands no reason and notifies differently.
        $this->assertFalse(TicketStatus::InProgress->isReopenEdge(TicketStatus::Pending));
        $this->assertFalse(TicketStatus::Fixed->isReopenEdge(TicketStatus::Closed));
    }

    /**
     * Every allowed transition must itself be legal - the map and the checker
     * cannot disagree.
     */
    public function test_allowed_transitions_agree_with_the_checker(): void
    {
        foreach (TicketStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $this->assertTrue($from->canTransitionTo($to));
            }

            foreach (TicketStatus::cases() as $to) {
                if (! in_array($to, $from->allowedTransitions(), true)) {
                    $this->assertFalse($from->canTransitionTo($to));
                }
            }
        }
    }

    /**
     * Touches label() for every case. A case added without extending the match
     * fatals here, which is the point.
     */
    public function test_every_case_has_a_label_and_appears_in_values(): void
    {
        foreach (TicketStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertContains($status->value, TicketStatus::values());
        }

        $this->assertSame(['pending', 'in_progress', 'fixed', 'closed'], TicketStatus::values());
    }
}
