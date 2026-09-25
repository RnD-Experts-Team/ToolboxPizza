<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One threshold, in minutes used, that this user wants flagged.
 */
class BreakMilestone extends Model
{
    protected $fillable = [
        'user_id',
        'threshold_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_minutes' => 'integer',
        ];
    }

    /**
     * The user's thresholds in minutes, ascending.
     *
     * @return array<int, int>
     */
    public static function thresholdsFor(User $user): array
    {
        return self::query()
            ->where('user_id', $user->id)
            ->orderBy('threshold_minutes')
            ->pluck('threshold_minutes')
            ->map(fn ($m) => (int) $m)
            ->all();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
