<?php

namespace Tests\Concerns;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Enums\WorkbookVisibleType;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Models\Workbook;
use App\Models\WorkbookColumn;
use App\Models\WorkbookFolder;
use App\Models\WorkbookRow;
use App\Models\WorkbookVisibilityRole;

/**
 * Stores, users, grants and a small workbook tree.
 *
 * Store, User and UserStoreRole get no factories on purpose: they are
 * replicated models, and the house rule is that replicated rows are created
 * explicitly because production never mints them either.
 */
trait BuildsWorkbookWorld
{
    protected Store $store;

    protected Store $otherStore;

    protected function buildWorkbookWorld(): void
    {
        $this->store = Store::query()->create([
            'id' => 1, 'store_number' => '03795-00001', 'name' => 'Downtown',
        ]);

        $this->otherStore = Store::query()->create([
            'id' => 2, 'store_number' => '03795-00002', 'name' => 'Uptown',
        ]);
    }

    protected function makeUser(int $id, string $name = 'User'): User
    {
        return User::query()->create([
            'id' => $id, 'name' => "{$name} {$id}", 'email' => "u{$id}@example.com",
        ]);
    }

    /**
     * The store CODE, never the id - that distinction is the point of the
     * table, and there is already a test elsewhere pinning it.
     */
    protected function grantStore(
        User $user,
        ?string $storeCode = null,
        string $roleName = 'shift_lead',
        bool $active = true,
    ): UserStoreRole {
        return UserStoreRole::query()->create([
            'id' => random_int(100000, 9999999),
            'user_id' => $user->id,
            'store_id' => $storeCode ?? $this->store->store_number,
            'role_name' => $roleName,
            'active' => $active,
        ]);
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function makeFolder(
        User $owner,
        WorkbookVisibility $visibility = WorkbookVisibility::OwnerOnly,
        array $roles = [],
        ?int $parentId = null,
        ?Store $store = null,
    ): WorkbookFolder {
        $folder = WorkbookFolder::query()->create([
            'parent_id' => $parentId,
            'store_id' => ($store ?? $this->store)->id,
            'created_by' => $owner->id,
            'name' => 'Folder',
            'visibility' => $visibility,
        ]);

        $this->tagRoles(WorkbookVisibleType::Folder, (int) $folder->id, $roles);

        return $folder;
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function makeWorkbook(
        WorkbookFolder $folder,
        User $owner,
        WorkbookVisibility $visibility = WorkbookVisibility::OwnerOnly,
        array $roles = [],
        ?Store $store = null,
    ): Workbook {
        $workbook = Workbook::query()->create([
            'workbook_folder_id' => $folder->id,
            'store_id' => ($store ?? $this->store)->id,
            'created_by' => $owner->id,
            'name' => 'Line Checks',
            'visibility' => $visibility,
        ]);

        $this->tagRoles(WorkbookVisibleType::Workbook, (int) $workbook->id, $roles);

        return $workbook;
    }

    protected function makeColumn(
        Workbook $workbook,
        string $name = 'Item',
        WorkbookColumnType $type = WorkbookColumnType::Text,
        int $position = 0,
        ?array $options = null,
    ): WorkbookColumn {
        return WorkbookColumn::query()->create([
            'workbook_id' => $workbook->id,
            'name' => $name,
            'type' => $type,
            'options' => $options,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function makeRow(
        Workbook $workbook,
        User $owner,
        WorkbookVisibility $visibility = WorkbookVisibility::OwnerOnly,
        array $roles = [],
        ?Store $store = null,
        int $position = 0,
    ): WorkbookRow {
        $row = WorkbookRow::query()->create([
            'workbook_id' => $workbook->id,
            'store_id' => ($store ?? $this->store)->id,
            'created_by' => $owner->id,
            'visibility' => $visibility,
            'position' => $position,
        ]);

        $this->tagRoles(WorkbookVisibleType::Row, (int) $row->id, $roles);

        return $row;
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function tagRoles(WorkbookVisibleType $type, int $id, array $roles): void
    {
        foreach ($roles as $role) {
            WorkbookVisibilityRole::query()->create([
                'visible_type' => $type->value,
                'visible_id' => $id,
                'role_name' => $role,
            ]);
        }
    }

    /**
     * A folder and a workbook in it, both tagged the same way, for the cases
     * that are only about ONE tag rather than about the chain.
     *
     * @param  array<int, string>  $roles
     * @return array{0: WorkbookFolder, 1: Workbook}
     */
    protected function openTreeFor(User $owner, WorkbookVisibility $visibility, array $roles = []): array
    {
        // The folder is all_stores_edit so it never caps anything - the point
        // of these fixtures is to isolate the workbook's own tag.
        $folder = $this->makeFolder($owner, WorkbookVisibility::AllStoresEdit);
        $workbook = $this->makeWorkbook($folder, $owner, $visibility, $roles);

        return [$folder, $workbook];
    }
}
