<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\Inventory\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * Daily writer for `stock_level_snapshots` (process gap G-01: `stock_levels`
 * is overwritten live, so without this every trend/cover/aging DSS silently
 * reads "now" as history).
 *
 * Insert-only, idempotent per (item, location, date): reruns are
 * insertOrIgnore no-ops. Chunked — never loads the table into memory.
 * No backfill exists or is possible; consumers must treat pre-first-snapshot
 * history as "data not available", never zero.
 */
class StockSnapshotService
{
    /** @return array{date: string, positions: int, inserted: int} */
    public function takeSnapshot(?string $date = null): array
    {
        $date ??= today()->toDateString();
        $now = now();
        $inserted = 0;
        $positions = 0;

        StockLevel::query()
            ->select(['id', 'item_id', 'location_id', 'quantity', 'reserved_quantity', 'weighted_avg_cost'])
            ->chunkById(500, function ($levels) use ($date, $now, &$inserted, &$positions): void {
                $rows = $levels->map(static fn ($level): array => [
                    'item_id' => $level->item_id,
                    'location_id' => $level->location_id,
                    'quantity' => $level->quantity,
                    'reserved_quantity' => $level->reserved_quantity,
                    'weighted_avg_cost' => $level->weighted_avg_cost,
                    'snapshot_date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                $positions += count($rows);
                $inserted += DB::table('stock_level_snapshots')->insertOrIgnore($rows);
            });

        return ['date' => $date, 'positions' => $positions, 'inserted' => $inserted];
    }
}
