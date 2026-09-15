<?php

namespace App\Enums;

/**
 * Which block of the picker a break type sits in.
 *
 * Presentation only — whether a break counts against the daily allowance is
 * break_types.counts_toward_limit, never this. They agree for every seeded row,
 * but the arithmetic must not depend on a label.
 */
enum BreakTypeGroup: string
{
    case Regular = 'regular';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Counted toward the limit',
            self::Special => 'Special breaks - excluded from limit',
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
