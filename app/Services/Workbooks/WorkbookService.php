<?php

namespace App\Services\Workbooks;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Enums\WorkbookVisibleType;
use App\Exceptions\WorkbookException;
use App\Models\Store;
use App\Models\User;
use App\Models\Workbook;
use App\Models\WorkbookColumn;
use App\Models\WorkbookFolder;
use App\Models\WorkbookRow;
use App\Models\WorkbookVisibilityRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writing folders and workbooks: creating, editing, moving, deleting, defining
 * columns, and retagging any of the three taggable resources.
 *
 * Every mutating method takes the acting user and checks their ability first.
 */
class WorkbookService
{
    public function __construct(private readonly WorkbookAccessService $access) {}

    // -------------------------------------------------------------------------
    // Folders
    // -------------------------------------------------------------------------

    /**
     * Created AT a store: that store becomes the folder's own, and it is what the
     * store_* tags resolve against. Putting a folder inside another one is an
     * edit OF THAT ONE, or a read-only share would be a place anyone could grow
     * a subtree.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function createFolder(User $actor, Store $store, array $data): WorkbookFolder
    {
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($parentId !== null) {
            $this->access->assertCan('add a folder to', $actor, $this->access->findFolder($actor, $parentId));
        }

        $folder = DB::transaction(function () use ($actor, $store, $data, $parentId) {
            $folder = WorkbookFolder::query()->create([
                'parent_id' => $parentId,
                'store_id' => $store->id,
                // The owner carve-out reads this, so it is the one attribute
                // that must never come from the request.
                'created_by' => $actor->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => WorkbookVisibility::OwnerOnly,
            ]);

            $this->tag($folder, $this->visibilityFrom($data), $data['visibility_roles'] ?? []);

            return $folder;
        });

        $this->access->refresh();

        return $folder->refresh()->load(['store', 'creator']);
    }

    /**
     * Rename, re-describe, retag, or move. Moving needs rights over the
     * destination too - otherwise a move is a way to put your content somewhere
     * you cannot write.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function updateFolder(User $actor, WorkbookFolder $folder, array $data): WorkbookFolder
    {
        $this->access->assertCan('edit', $actor, $folder);

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];

            if ($parentId !== null) {
                $this->access->assertCan('add a folder to', $actor, $this->access->findFolder($actor, $parentId));
            }

            // THE PREVENTION HALF of the cycle guard - the only thing between a
            // user and a subtree that eats itself.
            if ($this->access->wouldCycle((int) $folder->id, $parentId)) {
                throw WorkbookException::folderCycle($this->access->folderChain($parentId ?? 0));
            }

            $folder->parent_id = $parentId;
        }

        DB::transaction(function () use ($folder, $data) {
            $folder->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();

            if (array_key_exists('visibility', $data)) {
                $this->tag($folder, $this->visibilityFrom($data), $data['visibility_roles'] ?? []);
            }
        });

        $this->access->refresh();

        return $folder->refresh()->load(['store', 'creator']);
    }

    /**
     * Delete a folder and everything beneath it.
     *
     * The database cascades folders -> workbooks -> columns + rows -> cells, so a
     * bare DELETE on a populated folder would silently destroy a lot. It refuses
     * until the caller passes force, and the refusal says how much is at stake.
     *
     * @throws WorkbookException
     */
    public function deleteFolder(User $actor, WorkbookFolder $folder, bool $force = false): void
    {
        $this->access->assertCan('delete', $actor, $folder);

        $subtree = $this->access->folderDescendants((int) $folder->id);
        $childFolderIds = array_values(array_diff($subtree, [(int) $folder->id]));
        $workbookIds = Workbook::query()->whereIn('workbook_folder_id', $subtree)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (! $force && ($childFolderIds !== [] || $workbookIds !== [])) {
            throw WorkbookException::folderNotEmpty((int) $folder->id, count($childFolderIds), count($workbookIds));
        }

        DB::transaction(function () use ($folder, $childFolderIds, $workbookIds) {
            $rowIds = WorkbookRow::query()->whereIn('workbook_id', $workbookIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Role rows hang off a polymorphic key, which NOTHING cascades.
            // Cleared for the whole subtree first, because afterwards there is
            // no way left to find them - and a later resource reusing an id
            // would inherit the old audience.
            $this->forgetRoles(WorkbookVisibleType::Row, $rowIds);
            $this->forgetRoles(WorkbookVisibleType::Workbook, $workbookIds);
            $this->forgetRoles(WorkbookVisibleType::Folder, [...$childFolderIds, (int) $folder->id]);

            $folder->delete();
        });

        $this->access->refresh();
    }

    /**
     * The folders above a resource, root first, for a breadcrumb.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function breadcrumb(int $folderId): array
    {
        $ids = $this->access->folderChain($folderId);
        $names = WorkbookFolder::query()->whereIn('id', $ids)->pluck('name', 'id');

        return array_map(fn (int $id) => ['id' => $id, 'name' => $names[$id]], array_reverse($ids));
    }

    // -------------------------------------------------------------------------
    // Workbooks
    // -------------------------------------------------------------------------

    /**
     * The store is where the workbook is created, and need not be the folder's -
     * that is how one shared folder holds a workbook per store. Adding to a
     * folder is an edit of that folder.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function createWorkbook(User $actor, WorkbookFolder $folder, Store $store, array $data): Workbook
    {
        $this->access->assertCan('add a workbook to', $actor, $folder);

        if (($data['columns'] ?? []) === []) {
            throw WorkbookException::lastColumn();
        }

        $workbook = DB::transaction(function () use ($actor, $folder, $store, $data) {
            $workbook = Workbook::query()->create([
                'workbook_folder_id' => $folder->id,
                'store_id' => $store->id,
                'created_by' => $actor->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => WorkbookVisibility::OwnerOnly,
            ]);

            foreach (array_values($data['columns']) as $position => $column) {
                $this->writeColumn($workbook, $column, $position);
            }

            $this->tag($workbook, $this->visibilityFrom($data), $data['visibility_roles'] ?? []);

            return $workbook;
        });

        $this->access->refresh();

        return $workbook->refresh()->load(['store', 'creator', 'columns']);
    }

    /**
     * Name, description and visibility - NOT columns. They have their own
     * whole-list replace, because a partial update that also silently dropped
     * every unmentioned column would be a very expensive surprise.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    public function updateWorkbook(User $actor, Workbook $workbook, array $data): Workbook
    {
        $this->access->assertCan('edit', $actor, $workbook);

        DB::transaction(function () use ($workbook, $data) {
            $workbook->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();

            if (array_key_exists('visibility', $data)) {
                $this->tag($workbook, $this->visibilityFrom($data), $data['visibility_roles'] ?? []);
            }
        });

        $this->access->refresh();

        return $workbook->refresh()->load(['store', 'creator', 'columns']);
    }

    /**
     * Replace the whole column list. The submitted order IS the display order -
     * position is rewritten from the array index, so a delete leaves no gap and
     * a reorder is just a resubmission.
     *
     * A column absent from the submission is DELETED, taking its cells with it
     * by cascade. That is the only way to remove one, which is why every
     * submitted id is checked to belong to THIS workbook: the reference
     * implementation checked only that the id existed somewhere, so a
     * submission could quietly adopt another workbook's column.
     *
     * @param  array<int, array<string, mixed>>  $columns
     *
     * @throws WorkbookException
     */
    public function replaceColumns(User $actor, Workbook $workbook, array $columns): Workbook
    {
        $this->access->assertCan('change the columns of', $actor, $workbook);

        if ($columns === []) {
            throw WorkbookException::lastColumn();
        }

        DB::transaction(function () use ($workbook, $columns) {
            $kept = array_map('intval', array_filter(array_column($columns, 'id')));

            WorkbookColumn::query()
                ->where('workbook_id', $workbook->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->delete();

            foreach (array_values($columns) as $position => $column) {
                $this->writeColumn($workbook, $column, $position);
            }
        });

        return $workbook->refresh()->load(['store', 'creator', 'columns']);
    }

    /**
     * @throws WorkbookException
     */
    public function deleteWorkbook(User $actor, Workbook $workbook): void
    {
        $this->access->assertCan('delete', $actor, $workbook);

        DB::transaction(function () use ($workbook) {
            // Polymorphic role rows cascade nowhere; clear them while the ids are
            // still findable.
            $this->forgetRoles(WorkbookVisibleType::Row, WorkbookRow::query()->where('workbook_id', $workbook->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->forgetRoles(WorkbookVisibleType::Workbook, [(int) $workbook->id]);

            $workbook->delete();
        });

        $this->access->refresh();
    }

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    /**
     * Retag a folder, workbook or row. One method for all three, because the
     * tag is identical on each.
     *
     * @param  array<int, string>  $roleNames
     *
     * @throws WorkbookException
     */
    public function retag(User $actor, WorkbookFolder|Workbook|WorkbookRow $node, WorkbookVisibility $visibility, array $roleNames = []): void
    {
        $this->access->assertCan('change the visibility of', $actor, $node);

        DB::transaction(fn () => $this->tag($node, $visibility, $roleNames));

        $this->access->refresh();
    }

    /**
     * Set a tag and its role list together. They have to move as one: a
     * store_role_* tag with no roles reaches nobody, and a role list left behind
     * on a tag that no longer reads it would silently widen the tag the moment
     * somebody set it back to a role tag.
     *
     * Public so the row writer tags new rows the same way.
     *
     * @param  array<int, mixed>  $roleNames
     *
     * @throws WorkbookException
     */
    public function tag(Model $node, WorkbookVisibility $visibility, array $roleNames = []): void
    {
        // Role names are free text matching pizzasys - there is no local roles
        // table - so a stray space would be a tag that matches nobody and gives
        // no hint why. Trimmed and de-duplicated; nothing more is possible.
        $roleNames = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $roleNames), fn (string $n) => $n !== '')));

        if ($visibility->needsRoles() && $roleNames === []) {
            throw WorkbookException::rolesRequired($visibility->value);
        }

        $node->forceFill(['visibility' => $visibility])->save();

        $type = match (true) {
            $node instanceof WorkbookFolder => WorkbookVisibleType::Folder,
            $node instanceof Workbook => WorkbookVisibleType::Workbook,
            default => WorkbookVisibleType::Row,
        };

        // Replaced wholesale rather than diffed - it is a handful of strings.
        $this->forgetRoles($type, [(int) $node->getKey()]);

        if ($visibility->needsRoles()) {
            foreach ($roleNames as $roleName) {
                WorkbookVisibilityRole::query()->create(['visible_type' => $type->value, 'visible_id' => $node->getKey(), 'role_name' => $roleName]);
            }
        }
    }

    /**
     * NOTHING CASCADES ON A POLYMORPHIC KEY, so every delete path calls this by
     * hand. Forgetting it would leave rows a later resource with the same id
     * would inherit.
     *
     * @param  array<int, int>  $ids
     */
    public function forgetRoles(WorkbookVisibleType $type, array $ids): void
    {
        if ($ids !== []) {
            WorkbookVisibilityRole::query()->where('visible_type', $type->value)->whereIn('visible_id', $ids)->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Create or update one column at a position.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkbookException
     */
    private function writeColumn(Workbook $workbook, array $data, int $position): void
    {
        $type = WorkbookColumnType::from((string) ($data['type'] ?? WorkbookColumnType::Text->value));
        $options = null;

        if ($type->needsOptions()) {
            $options = array_values(array_unique(array_filter(
                array_map(fn ($o) => trim((string) $o), $data['options'] ?? []),
                fn (string $o) => $o !== '',
            )));

            // A choice column with no choices can never hold a value, so it is
            // refused rather than created unusable.
            if ($options === []) {
                throw WorkbookException::cellTypeMismatch((int) ($data['id'] ?? 0), $type->value);
            }
        }

        $attributes = [
            'name' => $data['name'],
            'type' => $type,
            'options' => $options,
            'required' => (bool) ($data['required'] ?? false),
            'position' => $position,
        ];

        if (! isset($data['id'])) {
            $workbook->columns()->create($attributes);

            return;
        }

        // Scoped to the workbook here as well as in validation - this is the
        // write that would otherwise re-parent somebody else's column. Fetched
        // and saved rather than mass-updated, so the casts apply.
        $column = $workbook->columns()->whereKey((int) $data['id'])->first()
            ?? throw WorkbookException::columnForeign((int) $data['id'], (int) $workbook->id);

        $column->fill($attributes)->save();
    }

    /**
     * Defaults to OwnerOnly rather than inheriting the parent's tag. Inheriting
     * would be friendlier right up until someone creates a folder inside an
     * all_stores_edit one and publishes it by accident.
     *
     * @param  array<string, mixed>  $data
     */
    private function visibilityFrom(array $data): WorkbookVisibility
    {
        return isset($data['visibility']) ? WorkbookVisibility::from((string) $data['visibility']) : WorkbookVisibility::OwnerOnly;
    }
}
