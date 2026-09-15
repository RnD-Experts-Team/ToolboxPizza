<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Breaks\BreakDayReportService;
use App\Services\Breaks\BreakMilestoneEvaluator;
use App\Services\Breaks\WorkDayResolver;
use Illuminate\Http\Request;

class BreakDayController extends Controller
{
    public function __construct(
        private readonly BreakDayReportService $report,
        private readonly BreakMilestoneEvaluator $milestones,
        private readonly WorkDayResolver $workDays,
    ) {}

    /**
     * The day breakdown. Defaults to the current work day.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): array
    {
        $workDate = $this->resolveDate($request);

        $this->evaluateIfOpen($request, $workDate);

        return ['data' => $this->report->forDay($request->user(), $workDate)];
    }

    /**
     * The same payload plus `text`: the ready-to-paste rendering.
     *
     * @return array<string, mixed>
     */
    public function export(Request $request): array
    {
        $workDate = $this->resolveDate($request);

        $this->evaluateIfOpen($request, $workDate);

        return ['data' => $this->report->export($request->user(), $workDate)];
    }

    private function resolveDate(Request $request): string
    {
        $date = $request->query('date');

        return is_string($date) && $date !== '' ? $date : $this->workDays->currentWorkDate();
    }

    /**
     * Milestones are evaluated ONLY for a work day that is still open.
     *
     * A stateless API has no in-process timer, so a crossing is detected when
     * someone asks - and this is the request that asks. Exporting last Tuesday
     * must never create firing rows, which is why a settled date is strictly
     * read-only.
     *
     * "Open" is the current work day PLUS the day of any break still running: a
     * break that started before the cutoff keeps yesterday's date and is still
     * accruing against it, so that day is not settled yet either.
     */
    private function evaluateIfOpen(Request $request, string $workDate): void
    {
        if (in_array($workDate, $this->milestones->openWorkDates($request->user()), true)) {
            $this->milestones->evaluate($request->user(), $workDate);
        }
    }
}
