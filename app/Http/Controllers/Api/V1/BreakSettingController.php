<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BreakMilestonesRequest;
use App\Http\Requests\Api\V1\BreakSettingsRequest;
use App\Models\BreakMilestone;
use App\Services\Breaks\BreakQueryService;
use App\Services\Breaks\BreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The break catalogue, and each user's own budget and milestone thresholds.
 *
 * There is no manager in this module: the person taking the breaks sets the
 * budget they are measured against.
 */
class BreakSettingController extends Controller
{
    public function __construct(
        private readonly BreakService $breaks,
        private readonly BreakQueryService $reader,
    ) {}

    /**
     * The break picker. Read-only: types are seeded, and retiring one is a
     * flag on the row, never a delete, because history points at it.
     */
    public function types(): JsonResponse
    {
        return response()->json(['data' => $this->reader->types()]);
    }

    /**
     * Creates the user's settings row on first read - there is no separate
     * "set up my breaks" step. Also hands back the work-day constants so the
     * client hardcodes nothing.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reader->settings($request->user())]);
    }

    public function update(BreakSettingsRequest $request): JsonResponse
    {
        $this->breaks->updateAllowance($request->user(), (int) $request->validated('daily_allowance_minutes'));

        return response()->json(['data' => $this->reader->settings($request->user())]);
    }

    public function milestones(Request $request): JsonResponse
    {
        return response()->json(['data' => BreakMilestone::thresholdsFor($request->user())]);
    }

    /**
     * Whole-list replace: the user states their set of milestones rather than
     * patching one at a time.
     */
    public function replaceMilestones(BreakMilestonesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->breaks->replaceThresholds(
            $request->user(),
            (array) $request->validated('thresholds'),
        )]);
    }

    public function destroyMilestone(Request $request, int $milestoneId): Response
    {
        $this->breaks->deleteMilestone($request->user(), $milestoneId);

        return response()->noContent();
    }
}
