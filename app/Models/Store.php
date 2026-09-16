<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Replicated from pizzasys via auth.v1.store.* - this service never mints a store.
 *
 * `store_number` is pizzasys' `store_id` string (e.g. "03795-00001") and is the
 * {storeId} segment in our API routes. It is ALSO the value
 * user_store_roles.store_id holds, which is why store access can be answered
 * locally without ever touching this table's integer primary key.
 */
class Store extends Model
{
    use ReplicatedModel, SoftDeletes;

    protected $fillable = [
        'id',
        'store_number',
        'name',
    ];

    /**
     * Identity only. `is_active` lives in pizzasys and is consulted there on
     * every request; a local copy could only ever go stale and disagree.
     */
    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
