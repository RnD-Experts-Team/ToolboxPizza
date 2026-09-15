<?php

namespace App\Enums;

/**
 * What a milestone firing represents.
 *
 * Two kinds, because a user may set a milestone AT their allowance (a 50-minute
 * threshold against a 50-minute budget) and those are two different things to
 * be told. They fire independently and are keyed separately.
 */
enum MilestoneKind: string
{
    case Milestone = 'milestone';
    case Allowance = 'allowance';

    public function label(): string
    {
        return match ($this) {
            self::Milestone => 'Milestone reached',
            self::Allowance => 'Allowance exceeded',
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
