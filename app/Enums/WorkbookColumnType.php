<?php

namespace App\Enums;

/**
 * What one column holds, and therefore which slot on workbook_cells its values
 * are written to.
 *
 * The reference implementation this feature is modelled on had no types at all:
 * every cell was one nullable text column, so "10" sorted before "2" and a date
 * range filter was impossible. The typed slot exists so ordering and comparison
 * are done by the database in the right domain.
 *
 * value_text is written for EVERY type regardless, holding the display string,
 * so the cross-column search is one LIKE rather than a four-way OR. See
 * WorkbookRowService.
 */
enum WorkbookColumnType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::LongText => 'Long text',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Boolean => 'Yes / No',
            self::Select => 'Choice',
        };
    }

    /**
     * The workbook_cells column this type sorts and filters on.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Text, self::LongText, self::Select => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_bool',
        };
    }

    /**
     * Text-ish types filter with LIKE; the rest compare exactly, because a
     * substring match on a number or a date is never what anyone meant.
     */
    public function filtersByLike(): bool
    {
        return $this->valueColumn() === 'value_text' && $this !== self::Select;
    }

    /** Only Select carries an options list, and it is required for it. */
    public function needsOptions(): bool
    {
        return $this === self::Select;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
