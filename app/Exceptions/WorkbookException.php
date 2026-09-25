<?php

namespace App\Exceptions;

class WorkbookException extends ToolboxException
{
    /**
     * Carries WHICH ancestor withheld the permission when it was not the
     * resource itself. With nested folders and most-restrictive-wins, "you
     * can't edit this" on its own is a support ticket every time.
     *
     * @param  array<string, mixed>  $capping
     */
    public static function forbidden(string $ability, array $capping = []): self
    {
        return new self(
            "You do not have permission to {$ability} this.",
            'WORKBOOK_FORBIDDEN',
            403,
            $capping === [] ? ['ability' => $ability] : ['ability' => $ability, 'capped_by' => $capping],
        );
    }

    /**
     * @param  array<int, int>  $path
     */
    public static function folderCycle(array $path): self
    {
        return new self('That parent would put the folder inside itself.', 'WORKBOOK_FOLDER_CYCLE', 422, ['path' => $path]);
    }

    public static function rolesRequired(string $visibility): self
    {
        return new self('That visibility needs at least one role name.', 'WORKBOOK_ROLES_REQUIRED', 422, ['visibility' => $visibility]);
    }

    public static function columnForeign(int $columnId, int $workbookId): self
    {
        return new self(
            'That column belongs to a different workbook.',
            'WORKBOOK_COLUMN_FOREIGN',
            422,
            ['column_id' => $columnId, 'workbook_id' => $workbookId],
        );
    }

    /**
     * For a choice column, hands back the allowed values so the UI can render
     * them as a dropdown.
     *
     * @param  array<int, string>  $allowed
     */
    public static function cellTypeMismatch(int $columnId, string $type, array $allowed = []): self
    {
        return new self(
            'That value does not fit the column type.',
            'WORKBOOK_CELL_TYPE_MISMATCH',
            422,
            ['column_id' => $columnId, 'type' => $type] + ($allowed === [] ? [] : ['allowed' => $allowed]),
        );
    }

    public static function lastColumn(): self
    {
        return new self('A workbook needs at least one column.', 'WORKBOOK_LAST_COLUMN', 422);
    }

    /**
     * Deleting a folder takes its whole subtree at the database level - too
     * much for a bare DELETE. The counts go in the client's confirmation, and it
     * re-sends with force=true.
     */
    public static function folderNotEmpty(int $folderId, int $folders, int $workbooks): self
    {
        return new self(
            'That folder still has things in it.',
            'WORKBOOK_FOLDER_NOT_EMPTY',
            409,
            ['folder_id' => $folderId, 'child_folders' => $folders, 'workbooks' => $workbooks, 'force_required' => true],
        );
    }

    public static function rowForeign(int $rowId, int $workbookId): self
    {
        return new self(
            'That row belongs to a different workbook.',
            'WORKBOOK_ROW_FOREIGN',
            422,
            ['row_id' => $rowId, 'workbook_id' => $workbookId],
        );
    }
}
