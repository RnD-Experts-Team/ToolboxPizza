<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketLevelRequest;
use App\Http\Requests\SyncTicketLevelSectionsRequest;
use App\Http\Requests\UpdateTicketLevelRequest;
use App\Models\TicketLevel;
use App\Services\Tickets\TicketLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketLevelController extends Controller
{
    public function __construct(private readonly TicketLevelService $levels) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        return ['data' => $this->levels->tree($request->boolean('include_inactive'))];
    }

    public function store(StoreTicketLevelRequest $request): JsonResponse
    {
        $level = $this->levels->create($request->validated());

        return response()->json(['data' => $this->levels->present($level->load('sections'))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateTicketLevelRequest $request, int $levelId): array
    {
        $level = TicketLevel::query()->findOrFail($levelId);

        return ['data' => $this->levels->present(
            $this->levels->update($level, $request->validated())->load('sections'),
        )];
    }

    /**
     * Whole-list replace, mirroring break-milestones: an empty array detaches
     * every section from this level.
     *
     * @return array<string, mixed>
     */
    public function syncSections(SyncTicketLevelSectionsRequest $request, int $levelId): array
    {
        $level = TicketLevel::query()->findOrFail($levelId);

        return ['data' => $this->levels->present(
            $this->levels->syncSections($level, (array) $request->validated('section_ids')),
        )];
    }

    /**
     * Retires the level. The response carries how many assignments this silences
     * - deactivating a mid-tree level severs the chain, so everyone assigned
     * above it stops receiving the sections below, and that should not be a
     * surprise.
     *
     * @return array<string, mixed>
     */
    public function destroy(int $levelId): array
    {
        $level = TicketLevel::query()->findOrFail($levelId);
        $affected = $this->levels->assignmentsAffectedByDeactivating($level);

        $this->levels->deactivate($level);

        return ['data' => [
            'id' => $level->id,
            'active' => false,
            'assignments_affected' => $affected,
        ]];
    }
}
