<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TicketAssignmentStoreRequest;
use App\Http\Requests\Api\V1\TicketAssignmentUpdateRequest;
use App\Http\Requests\Api\V1\TicketLevelSectionsRequest;
use App\Http\Requests\Api\V1\TicketLevelStoreRequest;
use App\Http\Requests\Api\V1\TicketLevelUpdateRequest;
use App\Http\Requests\Api\V1\TicketSectionStoreRequest;
use App\Http\Requests\Api\V1\TicketSectionUpdateRequest;
use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketSection;
use App\Services\Tickets\TicketCatalogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The routing catalogue an admin maintains: the sections pages tag themselves
 * with, the levels that group them, and who is assigned to either. Cross-store,
 * gated at pizzasys by the global `administer tickets` permission.
 */
class TicketCatalogController extends Controller
{
    public function __construct(private readonly TicketCatalogService $catalog) {}

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    /**
     * `?include_inactive=1` is for the admin screen, which has to show retired
     * sections in order to un-retire them.
     */
    public function sections(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->sections($request->boolean('include_inactive'))]);
    }

    public function storeSection(TicketSectionStoreRequest $request): JsonResponse
    {
        $section = $this->catalog->createSection($request->validated());

        return response()->json(['data' => $this->catalog->presentSection($section)], 201);
    }

    public function updateSection(TicketSectionUpdateRequest $request, int $sectionId): JsonResponse
    {
        $section = $this->catalog->updateSection(TicketSection::query()->findOrFail($sectionId), $request->validated());

        return response()->json(['data' => $this->catalog->presentSection($section)]);
    }

    /**
     * Retires rather than deletes - tickets point here and must keep rendering
     * their section's name. Un-retire with an update of `active: true`.
     */
    public function destroySection(int $sectionId): Response
    {
        TicketSection::query()->findOrFail($sectionId)->update(['active' => false]);

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Levels
    // -------------------------------------------------------------------------

    public function levels(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->levelTree($request->boolean('include_inactive'))]);
    }

    public function storeLevel(TicketLevelStoreRequest $request): JsonResponse
    {
        $level = $this->catalog->createLevel($request->validated());

        return response()->json(['data' => $this->catalog->presentLevel($level)], 201);
    }

    public function updateLevel(TicketLevelUpdateRequest $request, int $levelId): JsonResponse
    {
        $level = $this->catalog->updateLevel(TicketLevel::query()->findOrFail($levelId), $request->validated());

        return response()->json(['data' => $this->catalog->presentLevel($level)]);
    }

    /**
     * Whole-list replace: an empty array detaches every section from the level.
     */
    public function syncLevelSections(TicketLevelSectionsRequest $request, int $levelId): JsonResponse
    {
        $level = $this->catalog->syncLevelSections(
            TicketLevel::query()->findOrFail($levelId),
            (array) $request->validated('section_ids'),
        );

        return response()->json(['data' => $this->catalog->presentLevel($level)]);
    }

    /**
     * Retires the level, and says how many assignments that silences -
     * deactivating a mid-tree level severs the chain, so everyone assigned above
     * it stops receiving the sections below. That should not be a surprise.
     */
    public function destroyLevel(int $levelId): JsonResponse
    {
        $level = TicketLevel::query()->findOrFail($levelId);

        return response()->json(['data' => [
            'id' => $level->id,
            'active' => false,
            'assignments_affected' => $this->catalog->deactivateLevel($level),
        ]]);
    }

    // -------------------------------------------------------------------------
    // Assignments
    // -------------------------------------------------------------------------

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function assignments(Request $request): LengthAwarePaginator
    {
        return $this->catalog->assignments($request->only(['user_ids', 'section_ids', 'level_ids', 'per_page']));
    }

    public function storeAssignment(TicketAssignmentStoreRequest $request): JsonResponse
    {
        $assignment = $this->catalog->createAssignment($request->validated(), $request->user());

        return response()->json(['data' => $this->catalog->presentAssignment($assignment)], 201);
    }

    public function updateAssignment(TicketAssignmentUpdateRequest $request, int $assignmentId): JsonResponse
    {
        $assignment = $this->catalog->updateAssignment(TicketAssignment::query()->findOrFail($assignmentId), $request->validated());

        return response()->json(['data' => $this->catalog->presentAssignment($assignment)]);
    }

    /**
     * A real delete - an assignment is configuration, not history.
     */
    public function destroyAssignment(int $assignmentId): Response
    {
        TicketAssignment::query()->findOrFail($assignmentId)->delete();

        return response()->noContent();
    }
}
