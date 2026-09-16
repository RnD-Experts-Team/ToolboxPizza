<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Store;
use App\Models\Ticket;
use App\Services\Tickets\TicketAccessResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Resolve a ticket the caller is allowed to see, or 404.
 *
 * TWO filters, in order, and both matter:
 *
 *  1. Scoped to the store in the path, so a ticket id from another store is a
 *     404 here even when the caller legitimately has access to that other store.
 *  2. Run through TicketAccessResolver, which 404s rather than 403s when the
 *     caller has no relationship to the ticket - a 403 would confirm the id
 *     exists, which is enough to probe for them. Same reasoning as
 *     ResolvesOwnBreak.
 */
trait ResolvesVisibleTicket
{
    protected function visibleTicket(Store $store, int $ticketId): Ticket
    {
        $ticket = Ticket::query()
            ->where('store_id', $store->id)
            ->with(['store', 'section', 'reporter', 'participants.user'])
            ->findOrFail($ticketId);

        app(TicketAccessResolver::class)->assertCanView(Auth::user(), $ticket);

        return $ticket;
    }
}
