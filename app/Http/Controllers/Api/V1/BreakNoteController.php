<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesOwnBreak;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Services\Breaks\BreakQueryService;
use Illuminate\Http\JsonResponse;

class BreakNoteController extends Controller
{
    use ResolvesOwnBreak;

    public function __construct(private readonly BreakQueryService $reader) {}

    /**
     * Annotate a break. Allowed while it is still running and at any point
     * afterwards, for as long as the entry is retained - a user explaining a
     * long break the next morning is the normal case, not an edge one.
     */
    public function store(StoreNoteRequest $request, int $breakId): JsonResponse
    {
        $entry = $this->ownBreak($breakId);

        $note = $entry->notes()->create([
            'body' => $request->validated('body'),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(
            ['data' => $this->reader->presentNote($note->load('creator'))],
            201,
        );
    }
}
