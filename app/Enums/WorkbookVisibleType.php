<?php

namespace App\Enums;

/**
 * Which of the three taggable things a workbook_visibility_roles row belongs to.
 *
 * A short stable key rather than Laravel's morph map, because the join that
 * reads this table is hand-written SQL inside the visibility clause and a PHP
 * class name has no business appearing there. Renaming a model must not
 * invalidate stored rows.
 */
enum WorkbookVisibleType: string
{
    case Folder = 'folder';
    case Workbook = 'workbook';
    case Row = 'row';

    public function table(): string
    {
        return match ($this) {
            self::Folder => 'workbook_folders',
            self::Workbook => 'workbooks',
            self::Row => 'workbook_rows',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
