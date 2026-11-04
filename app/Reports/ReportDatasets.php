<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\TicketsDataset;

/**
 * The datasets this service offers the report builder. routes/api.php gives
 * each one a literal query route; each needs its own pizzasys auth rule.
 *
 * Breaks are not offered: break_entries belong to a user, not a store, and a
 * user can work at several, so a break cannot be tied to a store.
 */
final class ReportDatasets
{
    /** @var list<class-string<ReportDataset>> */
    public const DATASETS = [
        TicketsDataset::class,
    ];

    public static function make(string $key): ReportDataset
    {
        foreach (self::DATASETS as $dataset) {
            if ($dataset::KEY === $key) {
                return new $dataset();
            }
        }

        abort(404, 'Unknown dataset.');
    }
}
