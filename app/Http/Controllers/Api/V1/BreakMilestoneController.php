<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceBreakMilestonesRequest;
use App\Models\BreakMilestone;
use App\Services\Breaks\BreakSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BreakMilestoneController extends Controller
{
    public function __construct(private readonly BreakSettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        return ['data' => $this->settings->thresholds($request->user())];
    }

    /**
     * Whole-list replacement — the user states their set of milestones rather
     * than patching one at a time, so the client never has to diff.
     *
     * @return array<string, mixed>
     */
    public function replace(ReplaceBreakMilestonesRequest $request): array
    {
        return ['data' => $this->settings->replaceThresholds(
            $request->user(),
            (array) $request->validated('thresholds'),
        )];
    }

    public function destroy(Request $request, int $milestoneId): Response
    {
        // Scoped to the caller: another user's milestone id is a 404, not a 403,
        // so ids cannot be probed for existence.
        $milestone = BreakMilestone::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($milestoneId);

        $milestone->delete();

        return response()->noContent();
    }
}
