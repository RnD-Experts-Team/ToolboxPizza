<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\TicketStatus;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per ticket, by the store it was raised at and the local date it was
 * created. `count` counts tickets; deleted tickets are left out.
 */
class TicketsDataset extends ReportDataset
{
    public const KEY = 'toolbox.tickets';

    /** Timestamps are stored in UTC; the business reads them as New York dates. */
    private const REPORT_TIMEZONE = 'America/New_York';

    public function label(): string
    {
        return 'Tickets';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        $statuses = array_map(
            fn (TicketStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            TicketStatus::cases(),
        );

        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Created date', 'date', $this->dateColumn(), groupable: true),
            new Column('status', 'Status', 'enum', 'tickets.status', options: $statuses, groupable: true),
            new Column('section', 'Section', 'string', 'ticket_sections.name', groupable: true),
            new Column('reopen_count', 'Reopens', 'integer', 'tickets.reopen_count', filterable: false, measure: true, aggregations: ['sum']),
            new Column('count', 'Tickets', 'integer', 'tickets.id', filterable: false, measure: true, aggregations: ['count']),
            // Null until the first response, so the average covers answered tickets only.
            new Column('response_minutes', 'Minutes to first response', 'number', $this->responseMinutes(), filterable: false, measure: true, aggregations: ['avg']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('tickets')
            ->join('stores', 'stores.id', '=', 'tickets.store_id')
            ->join('ticket_sections', 'ticket_sections.id', '=', 'tickets.ticket_section_id')
            ->whereNull('tickets.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    /** The local date, so it compares with the period's Y-m-d bounds. */
    public function dateColumn(): string
    {
        return $this->mysql()
            ? "DATE(CONVERT_TZ(tickets.created_at, 'UTC', '".self::REPORT_TIMEZONE."'))"
            : 'DATE(tickets.created_at)';
    }

    private function responseMinutes(): string
    {
        return $this->mysql()
            ? 'TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.first_responded_at) / 60'
            : '(julianday(tickets.first_responded_at) - julianday(tickets.created_at)) * 1440';
    }

    /** The tests run on SQLite, which has neither CONVERT_TZ nor TIMESTAMPDIFF. */
    private function mysql(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }
}
