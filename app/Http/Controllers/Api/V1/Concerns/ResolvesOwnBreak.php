<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\BreakEntry;
use Illuminate\Support\Facades\Auth;

/**
 * Every {breakId} route resolves through here, so a break can only ever be
 * reached by the person it belongs to.
 *
 * Routes take a plain integer {breakId} rather than using route-model binding
 * precisely because of this: binding resolves the model BEFORE any ownership
 * filter could apply, which would make the two paths disagree about what a
 * missing break looks like.
 */
trait ResolvesOwnBreak
{
    protected function ownBreak(int $breakId): BreakEntry
    {
        // 404, never 403: a 403 would confirm that someone else's break id
        // exists, which is enough to probe the table.
        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', (int) Auth::id())
            ->findOrFail($breakId);
    }
}
