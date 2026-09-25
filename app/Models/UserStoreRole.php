<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store-scoped role grant, replicated from pizzasys.
 *
 * `store_id` is the store CODE ("03795-00001") or the literal 'all' - never
 * stores.id. See the migration for why that distinction is load-bearing.
 */
class UserStoreRole extends Model
{
    /**
     * Rows arrive over NATS carrying the source service's primary key, so the
     * id is supplied, never generated.
     */
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'user_id',
        'store_id',
        'role_name',
        'active',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grants that cover a given store code.
     *
     * 'all' is what our handler writes for an unscoped grant; NULL is the
     * estate's older shape for the same thing. Both are accepted, because rows
     * replicated before that convention settled are still live.
     *
     * @param  Builder<UserStoreRole>  $query
     * @return Builder<UserStoreRole>
     */
    public function scopeCoveringStore(Builder $query, string $storeCode): Builder
    {
        return $query->where(function (Builder $q) use ($storeCode) {
            $q->where('store_id', $storeCode)
                ->orWhere('store_id', 'all')
                ->orWhereNull('store_id');
        });
    }
}
