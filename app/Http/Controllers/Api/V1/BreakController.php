<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BreakNoteRequest;
use App\Http\Requests\Api\V1\BreakStartRequest;
use App\Http\Requests\Api\V1\BreakStoreRequest;
use App\Http\Requests\Api\V1\BreakUpdateRequest;
use App\Services\Breaks\BreakMilestoneService;
use App\Services\Breaks\BreakQueryService;
use App\Services\Breaks\BreakService;
use App\Services\Breaks\WorkDayResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The caller's own breaks. Every route acts on the authenticated user and
 * nobody else.
 */
class BreakController extends Controller
{
    public function __construct(
        private readonly BreakService $breaks,
        private readonly BreakQueryService $reader,
        private readonly BreakMilestoneService $milestones,
        private readonly WorkDayResolver $workDays,
    ) {}

    // -------------------------------------------------------------------------
    // The timer
    // -------------------------------------------------------------------------

    /**
     * The running break, or null.
     *
     * A READ THAT WRITES, deliberately: a stateless API has no in-process
     * timer, so a threshold crossed during a running break is only detected
     * when somebody asks - and a client polling this to tick its timer IS that
     * somebody. The unique index on break_milestone_firings makes it safe under
     * concurrent polls. Never prefetch, HEAD-probe or CDN-cache it.
     *
     * evaluateOpen(), not just today: a break that started before the cutoff
     * keeps YESTERDAY's work date and is still accruing against it.
     */
    public function active(Request $request): JsonResponse
    {
        $this->milestones->evaluateOpen($request->user());

        return response()->json(['data' => $this->reader->active($request->user())]);
    }

    public function start(BreakStartRequest $request): JsonResponse
    {
        $entry = $this->breaks->start(
            $request->user(),
            (int) $request->validated('break_type_id'),
            $request->validated('other_label'),
        );

        return response()->json(['data' => $this->reader->present($entry->fresh())], 201);
    }

    public function stop(Request $request, int $breakId): JsonResponse
    {
        $entry = $this->breaks->stop($this->reader->findOwn($request->user(), $breakId));

        return response()->json(['data' => $this->reader->present($entry->fresh())]);
    }

    // -------------------------------------------------------------------------
    // The day
    // -------------------------------------------------------------------------

    /**
     * Also a read that writes, but only for a work day that is still open -
     * see BreakMilestoneService::evaluateIfOpen().
     */
    public function day(Request $request): JsonResponse
    {
        $workDate = $this->workDate($request);
        $this->milestones->evaluateIfOpen($request->user(), $workDate);

        return response()->json(['data' => $this->reader->day($request->user(), $workDate)]);
    }

    /**
     * The same payload plus `text`, the ready-to-paste rendering. A separate
     * endpoint because the day view is the one clients poll.
     */
    public function export(Request $request): JsonResponse
    {
        $workDate = $this->workDate($request);
        $this->milestones->evaluateIfOpen($request->user(), $workDate);

        return response()->json(['data' => $this->reader->export($request->user(), $workDate)]);
    }

    // -------------------------------------------------------------------------
    // Entries
    // -------------------------------------------------------------------------

    /**
     * Returned as a bare paginator - the one list in this module that is.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->reader->index($request->user(), $request->only([
            'from', 'to', 'source', 'counts_toward_limit', 'break_type_ids', 'per_page',
        ]));
    }

    /**
     * Record a break the user forgot to time.
     */
    public function store(BreakStoreRequest $request): JsonResponse
    {
        $entry = $this->breaks->storeManual($request->user(), $request->validated());

        return response()->json(['data' => $this->reader->present($entry->fresh())], 201);
    }

    public function show(Request $request, int $breakId): JsonResponse
    {
        return response()->json(['data' => $this->reader->present($this->reader->findOwn($request->user(), $breakId))]);
    }

    public function update(BreakUpdateRequest $request, int $breakId): JsonResponse
    {
        $entry = $this->breaks->update($this->reader->findOwn($request->user(), $breakId), $request->validated());

        return response()->json(['data' => $this->reader->present($entry->fresh())]);
    }

    public function destroy(Request $request, int $breakId): Response
    {
        $this->breaks->delete($this->reader->findOwn($request->user(), $breakId));

        return response()->noContent();
    }

    public function storeNote(BreakNoteRequest $request, int $breakId): JsonResponse
    {
        $note = $this->breaks->addNote(
            $this->reader->findOwn($request->user(), $breakId),
            $request->user(),
            $request->validated('body'),
        );

        return response()->json(['data' => $this->reader->presentNote($note)], 201);
    }

    private function workDate(Request $request): string
    {
        $date = $request->query('date');

        return is_string($date) && $date !== '' ? $date : $this->workDays->currentWorkDate();
    }
}
