<?php

namespace App\Services\Workbooks;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Enums\WorkbookVisibleType;
use App\Exceptions\WorkbookException;
use App\Models\Store;
use App\Models\User;
use App\Models\Workbook;
use App\Models\WorkbookCell;
use App\Models\WorkbookColumn;
use App\Models\WorkbookRow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The grid: reading rows, and writing them and their typed cells.
 *
 * A cell writes ONE typed slot according to its column's type, and value_text
 * as well, always, holding the display string. The duplication buys a
 * single-clause cross-column search (one LIKE on value_text) and means a column
 * whose type later changes still renders. Sorting and filtering use the TYPED
 * slot, which is the whole reason the types exist - the reference
 * implementation stored everything as text, so "10" sorted before "2".
 */
class WorkbookRowService
{
    public function __construct(
        private readonly WorkbookAccessService $access,
        private readonly WorkbookService $workbooks,
        private readonly WorkbookQueryService $reader,
    ) {}

    // -------------------------------------------------------------------------
    // Reading the grid
    // -------------------------------------------------------------------------

    /**
     * One page of the grid, presented.
     *
     * Filtering and sorting happen in SQL on the typed slot, before pagination;
     * then the page's cells are fetched in ONE query. So the query count does
     * not grow with rows x columns - the reference read every cell through a
     * relation method that re-queried per call.
     *
     * The caller has already been allowed to view $workbook, which settles the
     * ancestor chain for every row in it; only each row's OWN tag is left.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function grid(User $viewer, Workbook $workbook, array $filters = []): LengthAwarePaginator
    {
        $columns = $workbook->columns;

        $query = WorkbookRow::query()->with(['store', 'creator'])->where('workbook_rows.workbook_id', $workbook->id);

        $this->access->scopeToVisible($query, $viewer, WorkbookVisibleType::Row);
        $this->applyColumnFilters($query, $columns, $filters['filter'] ?? []);
        $this->applySearch($query, (string) ($filters['search'] ?? ''));
        $this->applySort($query, $columns, $filters);

        $page = $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 200));

        $cells = WorkbookCell::query()
            ->whereIn('row_id', collect($page->items())->pluck('id'))
            ->get()
            ->groupBy('row_id');

        // The chain above the rows is identical for all of them, so it is
        // resolved ONCE rather than up to 200 times. A row the viewer can see
        // grants edit when its own tag does, or when they wrote it.
        $chainAllowsEdit = $this->access->canEdit($viewer, $workbook);

        return $page->through(fn (WorkbookRow $row) => $this->reader->presentRow(
            $row,
            $cells->get($row->id, new Collection),
            $columns,
            $chainAllowsEdit && ((int) $row->created_by === (int) $viewer->id || $row->visibility->grantsEdit()),
        ));
    }

    /**
     * filter[{columnId}]=value. Each is a whereExists on the column's typed slot
     * - not a join, which would multiply rows across several filters.
     *
     * An unparseable value is DROPPED, never 422'd - the house convention.
     * "abc" in a number filter means the user is still typing.
     *
     * @param  Builder<WorkbookRow>  $query
     * @param  Collection<int, WorkbookColumn>  $columns
     */
    private function applyColumnFilters(Builder $query, Collection $columns, mixed $submitted): void
    {
        if (! is_array($submitted)) {
            return;
        }

        foreach ($columns as $column) {
            $value = $submitted[(string) $column->id] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $predicate = $this->filterPredicate($column, (string) $value);

            if ($predicate !== null) {
                $query->whereExists(fn ($sub) => $predicate($sub->selectRaw('1')
                    ->from('workbook_cells')
                    ->whereColumn('workbook_cells.row_id', 'workbook_rows.id')
                    ->where('workbook_cells.column_id', $column->id)));
            }
        }
    }

    /**
     * How one column's filter narrows its cell, or null when the value does not
     * fit the column and the filter should be dropped.
     *
     * Three shapes, not one: text matches a substring, a number, choice or
     * yes/no matches exactly, and a DATE matches the whole day - equality on a
     * dateTime would only ever find midnight.
     */
    private function filterPredicate(WorkbookColumn $column, string $value): ?Closure
    {
        $slot = 'workbook_cells.'.$column->type->valueColumn();

        if ($column->type === WorkbookColumnType::Text || $column->type === WorkbookColumnType::LongText) {
            return fn ($q) => $q->where($slot, 'like', '%'.$value.'%');
        }

        if ($column->type === WorkbookColumnType::Select) {
            return fn ($q) => $q->where($slot, $value);
        }

        if ($column->type === WorkbookColumnType::Number) {
            return is_numeric($value) ? fn ($q) => $q->where($slot, $value) : null;
        }

        if ($column->type === WorkbookColumnType::Boolean) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $bool === null ? null : fn ($q) => $q->where($slot, $bool);
        }

        $time = strtotime($value);

        return $time === false
            ? null
            : fn ($q) => $q->whereBetween($slot, [date('Y-m-d 00:00:00', $time), date('Y-m-d 23:59:59', $time)]);
    }

    /**
     * Free text across every cell in the row: one LIKE on value_text, which
     * every column type writes regardless of its typed slot.
     *
     * @param  Builder<WorkbookRow>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if (trim($search) === '') {
            return;
        }

        $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('workbook_cells')
            ->whereColumn('workbook_cells.row_id', 'workbook_rows.id')
            ->where('workbook_cells.value_text', 'like', '%'.trim($search).'%'));
    }

    /**
     * By a column's typed values, or by the manual row order.
     *
     * A left join so rows with no cell for that column still appear. It CANNOT
     * fan out - (row_id, column_id) is unique - so the reference's
     * ->get()->unique('id') de-dupe is unnecessary.
     *
     * @param  Builder<WorkbookRow>  $query
     * @param  Collection<int, WorkbookColumn>  $columns
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, Collection $columns, array $filters): void
    {
        $direction = strtolower((string) ($filters['sort_order'] ?? 'asc'));

        // The reference passed this straight into orderBy(), which throws on
        // anything else - a 500 for a typo in a query string.
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $column = $columns->first(fn (WorkbookColumn $c) => (int) $c->id === (int) ($filters['sort_column'] ?? 0));

        if ($column === null) {
            // The id tiebreak means a page boundary never lands mid-tie and
            // repeats or drops a row.
            $query->orderBy('workbook_rows.position', $direction)->orderBy('workbook_rows.id', $direction);

            return;
        }

        $query->leftJoin('workbook_cells as sort_cell', fn ($join) => $join
            ->on('sort_cell.row_id', '=', 'workbook_rows.id')
            ->where('sort_cell.column_id', '=', $column->id))
            ->select('workbook_rows.*')
            ->orderBy('sort_cell.'.$column->type->valueColumn(), $direction)
            ->orderBy('workbook_rows.id', $direction);
    }

    // -------------------------------------------------------------------------
    // Writing rows
    // -------------------------------------------------------------------------

    /**
     * The store is the one the ROW is added at, which need not be the workbook's.
     * That is what lets every store add lines to one shared workbook and see
     * only its own.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function create(User $actor, Workbook $workbook, Store $store, array $data): WorkbookRow
    {
        $this->access->assertCan('add rows to', $actor, $workbook);

        $row = DB::transaction(function () use ($actor, $workbook, $store, $data) {
            $row = WorkbookRow::query()->create([
                'workbook_id' => $workbook->id,
                'store_id' => $store->id,
                'created_by' => $actor->id,
                'visibility' => WorkbookVisibility::OwnerOnly,
                // New rows go on the end.
                'position' => (int) WorkbookRow::query()->where('workbook_id', $workbook->id)->max('position') + 1,
            ]);

            $this->writeCells($row, $workbook, $data['cells'] ?? []);

            $this->workbooks->tag(
                $row,
                isset($data['visibility']) ? WorkbookVisibility::from((string) $data['visibility']) : WorkbookVisibility::OwnerOnly,
                $data['visibility_roles'] ?? [],
            );

            return $row;
        });

        $this->access->refresh();

        return $row->refresh()->load(['store', 'creator', 'cells']);
    }

    /**
     * A PARTIAL update: only the columns named in `cells` are touched.
     *
     * The ROW's own rights, not the workbook's - a workbook anyone at the store
     * may edit can still hold rows only their authors may change.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function update(User $actor, WorkbookRow $row, Workbook $workbook, array $data): WorkbookRow
    {
        $this->access->assertCan('edit', $actor, $row);

        DB::transaction(function () use ($row, $workbook, $data) {
            if (array_key_exists('cells', $data)) {
                $this->writeCells($row, $workbook, $data['cells']);
            }

            if (array_key_exists('visibility', $data)) {
                $this->workbooks->tag($row, WorkbookVisibility::from((string) $data['visibility']), $data['visibility_roles'] ?? []);
            }

            $row->touch();
        });

        $this->access->refresh();

        return $row->refresh()->load(['store', 'creator', 'cells']);
    }

    /**
     * Put the given rows in the given order. Rows not named keep their position,
     * so reordering a filtered view does not scramble the rest.
     *
     * A property of the WORKBOOK, not of any one row - it changes what everybody
     * sees - so it needs rights over the workbook.
     *
     * @param  array<int, int>  $rowIds  in the desired order
     *
     * @throws WorkbookException
     */
    public function reorder(User $actor, Workbook $workbook, array $rowIds): void
    {
        $this->access->assertCan('reorder the rows of', $actor, $workbook);

        $owned = WorkbookRow::query()->where('workbook_id', $workbook->id)->whereIn('id', $rowIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($rowIds as $rowId) {
            if (! in_array((int) $rowId, $owned, true)) {
                throw WorkbookException::rowForeign((int) $rowId, (int) $workbook->id);
            }
        }

        DB::transaction(function () use ($rowIds) {
            foreach (array_values($rowIds) as $position => $rowId) {
                WorkbookRow::query()->whereKey((int) $rowId)->update(['position' => $position]);
            }
        });
    }

    /**
     * @throws WorkbookException
     */
    public function delete(User $actor, WorkbookRow $row): void
    {
        $this->access->assertCan('delete', $actor, $row);

        DB::transaction(function () use ($row) {
            // Cells cascade; the polymorphic role rows do not.
            $this->workbooks->forgetRoles(WorkbookVisibleType::Row, [(int) $row->id]);
            $row->delete();
        });

        $this->access->refresh();
    }

    // -------------------------------------------------------------------------
    // Cells
    // -------------------------------------------------------------------------

    /**
     * Upserts on the (row_id, column_id) unique and touches only the cells
     * named. The reference deleted every cell and re-inserted, so a form that
     * dropped a field silently blanked it, and ids and created_at changed on
     * every edit.
     *
     * An empty value writes NO ROW: absent and empty mean the same thing for a
     * spreadsheet cell.
     *
     * @param  array<array-key, mixed>  $cells  column id => value
     *
     * @throws WorkbookException
     */
    private function writeCells(WorkbookRow $row, Workbook $workbook, array $cells): void
    {
        $columns = $workbook->columns->keyBy(fn (WorkbookColumn $c) => (int) $c->id);

        foreach ($cells as $columnId => $value) {
            // The reference validated nothing here, so a forged key pointing at
            // another workbook's column inserted happily - the foreign key only
            // checks that a column exists, not whose it is.
            $column = $columns->get((int) $columnId) ?? throw WorkbookException::columnForeign((int) $columnId, (int) $row->workbook_id);

            $slots = $this->slots($column, $value);

            if ($slots === null) {
                WorkbookCell::query()->where('row_id', $row->id)->where('column_id', $column->id)->delete();
            } else {
                WorkbookCell::query()->updateOrCreate(['row_id' => $row->id, 'column_id' => $column->id], $slots);
            }
        }
    }

    /**
     * The row that goes in workbook_cells for one submitted value, or null when
     * the cell is empty.
     *
     * @return array<string, mixed>|null
     *
     * @throws WorkbookException
     */
    private function slots(WorkbookColumn $column, mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $slots = ['value_text' => (string) $value, 'value_number' => null, 'value_date' => null, 'value_bool' => null];
        $mismatch = fn (array $allowed = []) => WorkbookException::cellTypeMismatch((int) $column->id, $column->type->value, $allowed);

        switch ($column->type) {
            case WorkbookColumnType::Select:
                // Handing back what IS allowed turns a dead end into a dropdown.
                $options = array_map('strval', $column->options ?? []);

                if (! in_array((string) $value, $options, true)) {
                    throw $mismatch($options);
                }
                break;

            case WorkbookColumnType::Number:
                if (! is_numeric(trim((string) $value))) {
                    throw $mismatch();
                }

                // Kept as a string all the way to the decimal(20,6) column -
                // through float it would lose precision. The display string is
                // the input as typed, so "1.50" does not come back as "1.5".
                $slots['value_number'] = trim((string) $value);
                $slots['value_text'] = trim((string) $value);
                break;

            case WorkbookColumnType::Date:
                try {
                    $date = CarbonImmutable::parse((string) $value);
                } catch (Throwable) {
                    throw $mismatch();
                }

                $slots['value_date'] = $date;
                // ISO-8601, so a text search for "2026-09" finds a month.
                $slots['value_text'] = $date->toIso8601String();
                break;

            case WorkbookColumnType::Boolean:
                $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($bool === null) {
                    throw $mismatch(['true', 'false', '1', '0', 'yes', 'no']);
                }

                $slots['value_bool'] = $bool;
                $slots['value_text'] = $bool ? 'true' : 'false';
                break;

            default:
                // Text and long text: value_text is the typed slot.
                break;
        }

        return $slots;
    }
}
