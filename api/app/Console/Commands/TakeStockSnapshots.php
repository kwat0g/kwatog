<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DSS\Services\StockSnapshotService;
use Illuminate\Console\Command;

/**
 * DSS Wave 0-5 — Scheduled daily at 03:45 via routes/console.php (after the
 * overnight safety-stock/replenishment batch, so the snapshot reflects the
 * settled position). Idempotent: reruns insert nothing new.
 */
class TakeStockSnapshots extends Command
{
    protected $signature = 'dss:snapshot-stock {--date= : Snapshot date (Y-m-d), defaults to today}';

    protected $description = 'Append-only daily snapshot of every stock position (DSS history basis)';

    public function handle(StockSnapshotService $snapshots): int
    {
        $stats = $snapshots->takeSnapshot($this->option('date'));
        $this->info("Stock snapshot {$stats['date']}: {$stats['inserted']} inserted ({$stats['positions']} positions).");

        return self::SUCCESS;
    }
}
