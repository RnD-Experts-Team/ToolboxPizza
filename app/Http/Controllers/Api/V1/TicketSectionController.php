<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketSectionRequest;
use App\Http\Requests\UpdateTicketSectionRequest;
use App\Models\TicketSection;
use App\Services\Tickets\TicketSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketSectionController extends Controller
{
    public function __construct(private readonly TicketSectionService $sections) {}

    /**
     * The picker. `?include_inactive=1` is for the admin screen, which has to
     * show retired sections in order to un-retire them.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        return ['data' => $this->sections
            ->index($request->boolean('include_inactive'))
            ->map(fn (TicketSection $s) => $this->sections->present($s))
            ->all()];
    }

    public function store(StoreTicketSectionRequest $request): JsonResponse
    {
        $section = $this->sections->create($request->validated());

        return response()->json(['data' => $this->sections->present($section->load('levels'))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateTicketSectionRequest $request, int $sectionId): array
    {
        $section = TicketSection::query()->findOrFail($sectionId);

        return ['data' => $this->sections->present(
            $this->sections->update($section, $request->validated())->load('levels'),
        )];
    }

    /**
     * Retires the section rather than deleting it - tickets point here and must
     * keep rendering their section's name.
     */
    public function destroy(int $sectionId): Response
    {
        $this->sections->deactivate(TicketSection::query()->findOrFail($sectionId));

        return response()->noContent();
    }
}
