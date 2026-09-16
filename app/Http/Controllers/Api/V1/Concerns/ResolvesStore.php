<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Store;
use App\Services\Tickets\Exceptions\TicketException;

trait ResolvesStore
{
    /**
     * Routes carry the store_number string ("03795-00001"), matching
     * HiringPizza and OperationsPizza - NOT the numeric pk that NATS events use.
     *
     * A raw string rather than route-model binding: the value the auth
     * middleware forwards to pizzasys is then the same code that appears in the
     * URL, which keeps the auth rule legible. Binding would send the bound
     * model's integer id instead.
     *
     * @throws TicketException
     */
    protected function resolveStore(string $storeNumber): Store
    {
        $store = Store::query()->where('store_number', $storeNumber)->first();

        if ($store === null) {
            // The tickets analogue of "user not synced yet": the store exists in
            // pizzasys but has not replicated here.
            throw TicketException::storeNotFound($storeNumber);
        }

        return $store;
    }
}
