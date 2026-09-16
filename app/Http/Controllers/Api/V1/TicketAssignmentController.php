<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketAssignmentRequest;
use App\Http\Requests\UpdateTicketAssignmentRequest;
use App\Models\TicketAssignment;
use App\Services\Tickets\TicketAssignmentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketAssignmentController extends Controller
{
    public function __construct(private readonly TicketAssignmentService $assignments) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->assignments->index($request->only([
            'user_ids', 'section_ids', 'level_ids', 'per_page',
        ]));
    }

    public function store(StoreTicketAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignments->create($request->validated(), $request->user());

        return response()->json([
            'data' => $this->assignments->present($assignment->load(['user', 'section', 'level'])),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateTicketAssignmentRequest $request, int $assignmentId): array
    {
        $assignment = TicketAssignment::query()->findOrFail($assignmentId);

        return ['data' => $this->assignments->present(
            $this->assignments->update($assignment, $request->validated())->load(['user', 'section', 'level']),
        )];
    }

    public function destroy(int $assignmentId): Response
    {
        $this->assignments->delete(TicketAssignment::query()->findOrFail($assignmentId));

        return response()->noContent();
    }
}
