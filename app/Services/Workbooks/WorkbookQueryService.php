<?php

namespace App\Services\Workbooks;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Enums\WorkbookVisibleType;
use App\Models\Store;
use App\Models\User;
use App\Models\Workbook;
use App\Models\WorkbookCell;
use App\Models\WorkbookColumn;
use App\Models\WorkbookFolder;
use App\Models\WorkbookRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reading folders and workbooks, and how every workbook resource is presented.
 *
 * Lists apply the visibility rules IN the query, before pagination, so a page
 * of 25 is 25 things the caller may actually open. Enums go out as ->value plus
 * a _label sibling and dates as toIso8601String(), like the rest of the API.
 */
class WorkbookQueryService
{
    public function __construct(private readonly WorkbookAccessService $access) {}

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    /**
     * Folders the viewer can see. `parent_id` absent means the whole tree, flat;
     * an explicit null means roots only. The two are different requests.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function folders(User $viewer, array $filters = []): LengthAwarePaginator
    {
        $query = WorkbookFolder::query()
            ->with(['store', 'creator'])
            ->whereIn('id', $this->access->viewableFolderIds($viewer) ?: [0])
            // The count is scoped too: telling someone a folder holds nine
            // workbooks when they can open two of them is a leak, just a quiet one.
            ->withCount(['workbooks' => fn (Builder $q) => $this->access->scopeToVisible($q, $viewer, WorkbookVisibleType::Workbook)]);

        if (array_key_exists('parent_id', $filters)) {
            $filters['parent_id'] === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', (int) $filters['parent_id']);
        }

        $this->applySearchAndSort($query, $filters, ['name', 'created_at', 'workbooks_count']);

        return $query->paginate($this->perPage($filters))
            ->through(fn (WorkbookFolder $f) => $this->presentFolder($f, $viewer, (int) $f->workbooks_count));
    }

    /**
     * Workbooks inside one folder. The caller has already been allowed to view
     * the folder, which settles the ancestor chain for everything in it - only
     * each workbook's OWN tag is left.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function workbooksIn(User $viewer, WorkbookFolder $folder, array $filters = []): LengthAwarePaginator
    {
        $query = Workbook::query()->with(['store', 'creator', 'columns'])->where('workbook_folder_id', $folder->id);

        $this->access->scopeToVisible($query, $viewer, WorkbookVisibleType::Workbook);
        $this->applySearchAndSort($query, $filters, ['name', 'created_at']);

        return $query->paginate($this->perPage($filters))
            ->through(fn (Workbook $w) => $this->presentWorkbook($w, $viewer, withColumns: true));
    }

    /**
     * An unrecognised sort falls back rather than 422s - the house convention
     * that an unparseable filter value is dropped silently. The id tiebreak is
     * also the reason there is no ->latest() here: the reference appended one,
     * which silently overrode every sort the user picked except the default.
     *
     * @param  Builder<WorkbookFolder|Workbook>  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $sortable
     */
    private function applySearchAndSort(Builder $query, array $filters, array $sortable): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }

        $column = in_array($filters['sort_by'] ?? null, $sortable, true) ? $filters['sort_by'] : 'created_at';
        $direction = in_array(strtolower((string) ($filters['sort_order'] ?? '')), ['asc', 'desc'], true) ? strtolower($filters['sort_order']) : 'desc';

        $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 25), 1), 200);
    }

    // -------------------------------------------------------------------------
    // Presentation
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function presentFolder(WorkbookFolder $folder, User $viewer, ?int $workbookCount = null, ?array $breadcrumb = null): array
    {
        $payload = [
            'id' => (int) $folder->id,
            'parent_id' => $folder->parent_id === null ? null : (int) $folder->parent_id,
            'name' => $folder->name,
            'description' => $folder->description,
            'store' => $this->store($folder->store),
            'created_by' => $this->user($folder->creator),
            ...$this->visibility($folder),
            'effective_visibility' => $this->access->effectiveVisibility($viewer, $folder),
            'created_at' => $folder->created_at?->toIso8601String(),
            'updated_at' => $folder->updated_at?->toIso8601String(),
            'viewer' => $this->access->capabilities($viewer, $folder),
        ];

        // Only when the caller asked for counts - a withCount on every read is a
        // join nobody asked for.
        if ($workbookCount !== null) {
            $payload['workbooks_count'] = $workbookCount;
        }

        if ($breadcrumb !== null) {
            $payload['breadcrumb'] = $breadcrumb;
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $breadcrumb
     * @return array<string, mixed>
     */
    public function presentWorkbook(Workbook $workbook, User $viewer, bool $withColumns = false, array $breadcrumb = []): array
    {
        $payload = [
            'id' => (int) $workbook->id,
            'folder_id' => (int) $workbook->workbook_folder_id,
            'name' => $workbook->name,
            'description' => $workbook->description,
            'store' => $this->store($workbook->store),
            'created_by' => $this->user($workbook->creator),
            ...$this->visibility($workbook),
            'effective_visibility' => $this->access->effectiveVisibility($viewer, $workbook),
            'created_at' => $workbook->created_at?->toIso8601String(),
            'updated_at' => $workbook->updated_at?->toIso8601String(),
            'viewer' => $this->access->capabilities($viewer, $workbook),
        ];

        if ($withColumns) {
            $payload['columns'] = $workbook->columns->map(fn (WorkbookColumn $c) => $this->presentColumn($c))->all();
        }

        if ($breadcrumb !== []) {
            $payload['breadcrumb'] = $breadcrumb;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentColumn(WorkbookColumn $column): array
    {
        return [
            'id' => (int) $column->id,
            'name' => $column->name,
            'type' => $column->type->value,
            'type_label' => $column->type->label(),
            // Always an array for a choice column and always null otherwise, so
            // the client never has to check the type to read it.
            'options' => $column->type->needsOptions() ? array_values($column->options ?? []) : null,
            'required' => (bool) $column->required,
            'position' => (int) $column->position,
        ];
    }

    /**
     * One grid row. The cells are handed in already grouped rather than read
     * off the model - touching the relation here is what made the reference
     * cost one query per cell.
     *
     * @param  Collection<int, WorkbookCell>  $cells
     * @param  Collection<int, WorkbookColumn>  $columns
     * @return array<string, mixed>
     */
    public function presentRow(WorkbookRow $row, Collection $cells, Collection $columns, bool $canEdit): array
    {
        $byColumn = $cells->keyBy(fn (WorkbookCell $cell) => (int) $cell->column_id);
        $values = [];

        // Every column gets a key, present or not: a sparse map would make the
        // client tell "no cell" from "empty cell", and they mean the same thing.
        foreach ($columns as $column) {
            $cell = $byColumn->get((int) $column->id);
            $values[(string) $column->id] = $cell === null ? null : $this->cellValue($column, $cell);
        }

        return [
            'id' => (int) $row->id,
            'workbook_id' => (int) $row->workbook_id,
            'position' => (int) $row->position,
            'store' => $this->store($row->store),
            'created_by' => $this->user($row->creator),
            ...$this->visibility($row),
            'cells' => $values,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'viewer' => ['can' => [
                'view' => true,
                'edit' => $canEdit,
                'change_visibility' => $canEdit,
                'delete' => $canEdit,
            ]],
        ];
    }

    /**
     * A row on its own, outside the grid - resolves its own chain.
     *
     * @param  Collection<int, WorkbookColumn>  $columns
     * @return array<string, mixed>
     */
    public function presentSingleRow(WorkbookRow $row, Collection $columns, User $viewer): array
    {
        return [
            ...$this->presentRow($row, $row->cells, $columns, $this->access->canEdit($viewer, $row)),
            'effective_visibility' => $this->access->effectiveVisibility($viewer, $row),
        ];
    }

    /**
     * The labelled tag and column-type catalogues, so the UI hardcodes neither
     * the strings nor their wording.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'visibilities' => array_map(fn (WorkbookVisibility $v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'audience' => $v->audience()->value,
                'audience_label' => $v->audience()->label(),
                'grants_edit' => $v->grantsEdit(),
                // The one field that changes the editor's shape: show the role
                // picker and require at least one entry.
                'needs_roles' => $v->needsRoles(),
            ], WorkbookVisibility::cases()),
            'column_types' => array_map(fn (WorkbookColumnType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'needs_options' => $t->needsOptions(),
            ], WorkbookColumnType::cases()),
        ];
    }

    /**
     * The stored tag plus its role list - identical on all three resources.
     *
     * @return array<string, mixed>
     */
    private function visibility(Model $node): array
    {
        /** @var WorkbookVisibility $visibility */
        $visibility = $node->getAttribute('visibility');

        return [
            'visibility' => $visibility->value,
            'visibility_label' => $visibility->label(),
            // Always an array for a role tag and always null otherwise.
            'visibility_roles' => $visibility->needsRoles() ? $this->access->roleNamesOf($node) : null,
        ];
    }

    private function cellValue(WorkbookColumn $column, WorkbookCell $cell): mixed
    {
        return match ($column->type) {
            // A number comes from value_text - what the user actually typed -
            // not the decimal slot, which renders differently per driver ("1.5"
            // on SQLite, "1.500000" on MySQL) and would lose "1.50". It is still
            // numeric; the typed slot exists to sort and filter in SQL.
            WorkbookColumnType::Number => $cell->value_text ?? ($cell->value_number === null ? null : (string) $cell->value_number),
            WorkbookColumnType::Date => $cell->value_date?->toIso8601String(),
            WorkbookColumnType::Boolean => $cell->value_bool === null ? null : (bool) $cell->value_bool,
            default => $cell->value_text,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function store(?Store $store): ?array
    {
        // The CODE is what the client puts back in a URL.
        return $store === null ? null : ['id' => (int) $store->id, 'store_number' => $store->store_number, 'name' => $store->name];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => (int) $user->id, 'name' => $user->name];
    }
}
