<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own break budget. Also the lock target for the "one open break at a
 * time" invariant — see the table's migration for why that lives here.
 */
class UserBreakSetting extends Model
{
    protected $fillable = [
        'user_id',
        'daily_allowance_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_allowance_minutes' => 'integer',
        ];
    }

    /**
     * The user's row, created on first touch from the configured default - so a
     * client never runs a setup step, and so the row exists before anything
     * tries to lock it.
     */
    public static function forUser(User $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['daily_allowance_minutes' => (int) config('toolbox.breaks.default_daily_allowance_minutes')],
        );
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The budget in seconds. All break arithmetic is done in seconds; minutes
     * exist only at the edges, where the user types and reads them.
     */
    public function allowanceSeconds(): int
    {
        return $this->daily_allowance_minutes * 60;
    }
}
