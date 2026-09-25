<?php

namespace App\Exceptions;

class BreakException extends ToolboxException
{
    /**
     * Carries the running break so the UI can offer "end it and start this one"
     * rather than a dead end.
     *
     * @param  array<string, mixed>  $running
     */
    public static function alreadyOnBreak(array $running): self
    {
        return new self(
            'You are already on a break. End it before starting another.',
            'ALREADY_ON_BREAK',
            409,
            ['running' => $running],
        );
    }

    public static function notRunning(int $breakId): self
    {
        return new self('That break has already ended.', 'BREAK_NOT_RUNNING', 409, ['break_id' => $breakId]);
    }

    /**
     * Carries the conflicting entries so a timeline can highlight them.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public static function overlap(array $conflicts): self
    {
        return new self(
            'That time overlaps a break you already have.',
            'BREAK_OVERLAP',
            409,
            ['conflicts' => $conflicts],
        );
    }

    public static function endsBeforeStart(): self
    {
        return new self('A break cannot end before it started.', 'BREAK_ENDS_BEFORE_START', 422);
    }

    public static function startsInFuture(): self
    {
        return new self('A break cannot start in the future.', 'BREAK_STARTS_IN_FUTURE', 422);
    }

    public static function outsideRetentionWindow(string $oldestWorkDate): self
    {
        return new self(
            "Breaks are only kept for a limited time; nothing can be recorded before {$oldestWorkDate}.",
            'BREAK_OUTSIDE_RETENTION_WINDOW',
            422,
            ['oldest_work_date' => $oldestWorkDate],
        );
    }

    public static function customLabelRequired(string $typeName): self
    {
        return new self(
            "\"{$typeName}\" needs a short description of what the break was.",
            'BREAK_CUSTOM_LABEL_REQUIRED',
            422,
        );
    }

    public static function customLabelNotAllowed(string $typeName): self
    {
        return new self(
            "\"{$typeName}\" already describes the break; remove the custom label.",
            'BREAK_CUSTOM_LABEL_NOT_ALLOWED',
            422,
        );
    }

    public static function typeInactive(string $typeName): self
    {
        return new self("\"{$typeName}\" is no longer available to choose.", 'BREAK_TYPE_INACTIVE', 422);
    }
}
