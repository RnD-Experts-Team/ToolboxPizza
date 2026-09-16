<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketParticipantRole;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketParticipantRequest;
use App\Models\TicketParticipant;
use App\Services\Tickets\TicketParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketParticipantController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(private readonly TicketParticipantService $participants) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request, string $storeId, int $ticketId): array
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        return ['data' => $this->participants->forTicket($ticket)
            ->map(fn (TicketParticipant $p) => $this->participants->present($p))
            ->all()];
    }

    /**
     * Adding someone is a routing decision, so it needs the manage-participants
     * ability - enforced inside the service, which is also what rejects a row
     * for the reporter.
     */
    public function store(StoreTicketParticipantRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $participant = $this->participants->add(
            $request->user(),
            $ticket,
            (int) $request->validated('user_id'),
            TicketParticipantRole::from($request->validated('role')),
        );

        return response()->json(['data' => $this->participants->present($participant)], 201);
    }

    public function destroy(Request $request, string $storeId, int $ticketId, int $userId): Response
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $this->participants->remove($request->user(), $ticket, $userId);

        return response()->noContent();
    }
}
