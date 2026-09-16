<?php

namespace App\Services\Tickets\Exceptions;

use App\Enums\TicketStatus;
use App\Enums\TicketViewerRole;
use RuntimeException;
use Throwable;

/**
 * A domain failure the API renders as a structured error. `errorCode` is the
 * contract a client branches on; `context` becomes the rest of `error`.
 *
 * Mirrors BreakException deliberately rather than sharing a base class - one
 * extra file beats refactoring a green module for cosmetics.
 */
class TicketException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 409,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function illegalTransition(TicketStatus $from, TicketStatus $to): self
    {
        return new self(
            "A {$from->label()} ticket cannot move to {$to->label()}.",
            'TICKET_ILLEGAL_TRANSITION',
            409,
            [
                'from' => $from->value,
                'to' => $to->value,
                // Handing back what IS allowed turns a dead end into something
                // the UI can render as buttons.
                'allowed' => array_map(fn (TicketStatus $s) => $s->value, $from->allowedTransitions()),
            ],
        );
    }

    public static function alreadyInStatus(TicketStatus $status): self
    {
        return new self(
            "This ticket is already {$status->label()}.",
            'TICKET_ALREADY_IN_STATUS',
            409,
            ['status' => $status->value],
        );
    }

    public static function reopenReasonRequired(): self
    {
        return new self(
            'Reopening a ticket needs a reason.',
            'TICKET_REOPEN_REASON_REQUIRED',
            422,
        );
    }

    public static function notReopenable(TicketStatus $status): self
    {
        return new self(
            "A {$status->label()} ticket is not closed, so there is nothing to reopen.",
            'TICKET_NOT_REOPENABLE',
            409,
            ['status' => $status->value],
        );
    }

    public static function forbidden(string $ability, TicketViewerRole $role): self
    {
        return new self(
            "You do not have permission to {$ability} this ticket.",
            'TICKET_FORBIDDEN',
            403,
            ['ability' => $ability, 'role' => $role->value],
        );
    }

    public static function sectionInactive(string $key): self
    {
        return new self(
            "\"{$key}\" is no longer accepting new tickets.",
            'TICKET_SECTION_INACTIVE',
            422,
            ['section_key' => $key],
        );
    }

    /**
     * @param  array<int, int>  $path
     */
    public static function levelCycle(array $path): self
    {
        return new self(
            'That parent would put the level inside itself.',
            'TICKET_LEVEL_CYCLE',
            422,
            ['path' => $path],
        );
    }

    public static function assignmentTargetRequired(): self
    {
        return new self(
            'An assignment needs either a section or a level.',
            'TICKET_ASSIGNMENT_TARGET_REQUIRED',
            422,
        );
    }

    public static function assignmentTargetAmbiguous(): self
    {
        return new self(
            'An assignment takes a section or a level, not both.',
            'TICKET_ASSIGNMENT_TARGET_AMBIGUOUS',
            422,
        );
    }

    public static function assignmentDuplicate(): self
    {
        return new self(
            'That user is already assigned here.',
            'TICKET_ASSIGNMENT_DUPLICATE',
            409,
        );
    }

    public static function participantRedundant(string $why): self
    {
        return new self(
            $why,
            'TICKET_PARTICIPANT_REDUNDANT',
            422,
        );
    }

    public static function storeNotFound(string $storeNumber): self
    {
        return new self(
            "Store {$storeNumber} is not known here yet.",
            'STORE_NOT_FOUND',
            404,
            ['store_number' => $storeNumber],
        );
    }
}
