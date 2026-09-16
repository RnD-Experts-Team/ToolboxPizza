<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reported problem, raised against a section at a store.
 *
 * @property-read Collection<int, TicketResponse> $responses
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasAttachments, HasFactory, HasNotes, SoftDeletes;

    protected $fillable = [
        'store_id',
        'ticket_section_id',
        'title',
        'description',
        'reported_by',
        'status',
        'first_responded_at',
        'fixed_at',
        'closed_at',
        'reopened_at',
        'reopen_count',
        'last_activity_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'first_responded_at' => 'immutable_datetime',
            'fixed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'reopen_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<TicketSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TicketSection::class, 'ticket_section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return HasMany<TicketResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(TicketResponse::class)->orderBy('id');
    }

    /** @return HasMany<TicketParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(TicketParticipant::class);
    }

    /** @return HasMany<TicketStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class)->orderBy('id');
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
