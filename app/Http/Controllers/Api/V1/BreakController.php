<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesOwnBreak;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakRequest;
use App\Http\Requests\UpdateBreakRequest;
use App\Services\Breaks\BreakQueryService;
use App\Services\Breaks\BreakWriteService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BreakController extends Controller
{
    use ResolvesOwnBreak;

    public function __construct(
        private readonly BreakWriteService $writer,
        private readonly BreakQueryService $reader,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        // `from`/`to` are WORK dates, not calendar dates - a break that ran past
        // midnight is filed under the day it started.
        return $this->reader->index($request->user(), $request->only([
            'from', 'to', 'source', 'counts_toward_limit', 'break_type_ids', 'per_page',
        ]));
    }

    /**
     * Record a break the user forgot to time.
     */
    public function store(StoreBreakRequest $request): JsonResponse
    {
        $entry = $this->writer->storeManual($request->user(), $request->validated());

        return response()->json(['data' => $this->reader->present($entry->fresh(['breakType', 'notes.creator']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, int $breakId): array
    {
        return ['data' => $this->reader->present($this->ownBreak($breakId))];
    }

    /**
     * Correct a break. POST rather than PUT/PATCH: the house convention across
     * these services.
     *
     * @return array<string, mixed>
     */
    public function update(UpdateBreakRequest $request, int $breakId): array
    {
        [$entry] = $this->writer->update($this->ownBreak($breakId), $request->validated());

        return ['data' => $this->reader->present($entry->fresh(['breakType', 'notes.creator']))];
    }

    public function destroy(Request $request, int $breakId): Response
    {
        $this->writer->delete($this->ownBreak($breakId));

        return response()->noContent();
    }
}
