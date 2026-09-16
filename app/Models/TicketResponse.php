<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Database\Factories\TicketResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reply on a ticket. Can carry its own files.
 */
class TicketResponse extends Model
{
    /** @use HasFactory<TicketResponseFactory> */
    use HasAttachments, HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
