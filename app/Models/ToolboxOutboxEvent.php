<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the transactional outbox. Domain writes record an event here
 * inside their own DB transaction, so an event can never be published for a
 * change that rolled back — and can never be lost for one that committed.
 *
 * PublishOutboxEventJob drains it as the fast path; `outbox:publish-pending`
 * is the sweeper that catches anything the queue dropped.
 */
class ToolboxOutboxEvent extends Model
{
    use HasUlids;

    protected $table = 'toolbox_outbox_events';

    protected $fillable = [
        'subject',
        'type',
        'payload',
        'attempts',
        'last_error',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
