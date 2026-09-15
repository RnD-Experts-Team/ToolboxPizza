<?php

namespace App\Enums;

/**
 * How a break entry came to exist.
 *
 * ENTRY ORIGIN, not an edit trail. There is deliberately no revision table, no
 * edited_at and no edit counter in this service — an edit overwrites in place
 * and leaves no history. Do not read this as "was this changed".
 */
enum BreakSource: string
{
    case Timer = 'timer';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Timer => 'Timed',
            self::Manual => 'Entered by hand',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
