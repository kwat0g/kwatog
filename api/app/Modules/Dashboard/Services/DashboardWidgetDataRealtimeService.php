<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\DashboardWidgetDataChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces widget-cache generation changes into one durable transaction ping. */
final class DashboardWidgetDataRealtimeService
{
    private static ?string $lastStagedTransactionId = null;

    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        // Dashboard invalidation may touch many widget scopes for one write.
        // Avoid repeated outbox updates/queries for the same PostgreSQL tx;
        // OutboxService still provides durable dedupe across callers/processes.
        if ($transactionId === self::$lastStagedTransactionId) {
            return;
        }
        self::$lastStagedTransactionId = $transactionId;
        DB::afterCommit(static function () use ($transactionId): void {
            if (self::$lastStagedTransactionId === $transactionId) {
                self::$lastStagedTransactionId = null;
            }
        });

        app(OutboxService::class)->recordCoalesced(
            new DashboardWidgetDataChanged,
            "dashboard-widget-data-refresh:{$transactionId}",
        );
    }
}
