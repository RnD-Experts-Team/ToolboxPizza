<?php

namespace App\Models;

use App\Exceptions\ToolboxException;
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
    use SoftDeletes;

    /**
     * Rows arrive over NATS carrying the source service's primary key, so the
     * id is supplied, never generated.
     */
    public $incrementing = false;

    protected $keyType = 'int';

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

    /**
     * The store a route names. Routes carry the store CODE ("03795-00001"),
     * never the pk, so the value pizzasys authorised is literally the URL
     * segment. Resolved by hand rather than by route-model binding for that
     * reason: binding would forward the model's integer id instead.
     *
     * @throws ToolboxException 404 STORE_NOT_FOUND when it has not replicated here yet
     */
    public static function resolveByNumber(string $storeNumber): self
    {
        return self::query()->where('store_number', $storeNumber)->first()
            ?? throw ToolboxException::storeNotFound($storeNumber);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
