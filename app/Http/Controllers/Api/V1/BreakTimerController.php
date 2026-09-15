<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesOwnBreak;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartBreakRequest;
use App\Services\Breaks\BreakMilestoneEvaluator;
use App\Services\Breaks\BreakQueryService;
use App\Services\Breaks\BreakWriteService;
use App\Services\Breaks\WorkDayResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live timer: start now, stop now.
 *
 * Retroactive entries and corrections live on BreakController, because they are
 * a different act - the user telling us about a break rather than taking one.
 */
class BreakTimerController extends Controller
{
    use ResolvesOwnBreak;

    public function __construct(
        private readonly BreakWriteService $writer,
        private readonly BreakQueryService $reader,
        private readonly BreakMilestoneEvaluator $milestones,
        private readonly WorkDayResolver $workDays,
    ) {}

    public function start(StartBreakRequest $request): JsonResponse
    {
        $entry = $this->writer->start(
            $request->user(),
            (int) $request->validated('break_type_id'),
            $request->validated('other_label'),
        );

        return response()->json(['data' => $this->reader->present($entry->fresh(['breakType', 'notes.creator']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(Request $request, int $breakId): array
    {
        $entry = $this->writer->stop($this->ownBreak($breakId));

        return ['data' => $this->reader->present($entry->fresh(['breakType', 'notes.creator']))];
    }

    /**
     * The break currently running, or null.
     *
     * @return array<string, mixed>
     */
    public function active(Request $request): array
    {
        // A read that writes. Deliberate: a stateless API has no in-process
        // timer, so a threshold crossed during a running break is only detected
        // when somebody asks - and a client polling this endpoint to tick its
        // timer IS that somebody. The unique index on break_milestone_firings
        // makes it safe under concurrent polls. These routes must therefore not
        // be prefetched, HEAD-probed or CDN-cached.
        //
        // evaluateOpen(), not evaluate(currentWorkDate): a break that started
        // before the cutoff keeps YESTERDAY's work date, so evaluating only
        // today would stop noticing its milestones the moment 06:00 passed.
        $this->milestones->evaluateOpen($request->user());

        return ['data' => $this->reader->active($request->user())];
    }
}
