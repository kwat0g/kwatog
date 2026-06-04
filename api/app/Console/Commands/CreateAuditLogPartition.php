<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates the NEXT calendar month's child partition for the audit_logs table.
 *
 * PostgreSQL declarative partitioning requires child partitions to exist before
 * data is inserted — if November ends and there is no December partition, every
 * audit_log INSERT will fail with "no partition of relation found".
 *
 * Run this command once per month, well before the month boundary.
 *
 * --- Scheduling (add to routes/console.php) --------------------------------
 *
 *   Schedule::command('audit-log:create-partition')
 *       ->monthlyOn(1, '00:05')   // 1st of each month at 00:05
 *       ->runInBackground()
 *       ->withoutOverlapping()
 *       ->onFailure(function () {
 *           Log::critical('audit-log:create-partition failed — audit_logs may stop accepting writes next month');
 *       });
 *
 * ---------------------------------------------------------------------------
 *
 * Usage:
 *   php artisan audit-log:create-partition            # creates next month
 *   php artisan audit-log:create-partition --offset=2 # creates month after next
 */
class CreateAuditLogPartition extends Command
{
    protected $signature = 'audit-log:create-partition
                            {--offset=1 : How many months ahead to create (default: 1 = next month)}';

    protected $description = 'Create the next monthly child partition for the audit_logs partitioned table';

    public function handle(): int
    {
        $offset = (int) $this->option('offset');

        if ($offset < 1) {
            $this->error('--offset must be >= 1. Use 1 for next month, 2 for the month after, etc.');
            return self::FAILURE;
        }

        // Calculate the target month boundary dates.
        $targetStart = now()->startOfMonth()->addMonths($offset);
        $targetEnd   = $targetStart->copy()->addMonth();

        $suffix     = $targetStart->format('Y_m');           // e.g. 2027_02
        $tableName  = "audit_logs_{$suffix}";                // audit_logs_2027_02
        $fromDate   = $targetStart->format('Y-m-d');         // 2027-02-01
        $toDate     = $targetEnd->format('Y-m-d');           // 2027-03-01

        $this->line("Target partition : <info>{$tableName}</info>");
        $this->line("Range            : <info>[{$fromDate}, {$toDate})</info>");

        // Idempotent — IF NOT EXISTS means re-running on the same month is safe.
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName}
                PARTITION OF audit_logs
                FOR VALUES FROM ('{$fromDate}') TO ('{$toDate}')
        SQL;

        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            $message = "Failed to create partition {$tableName}: {$e->getMessage()}";
            $this->error($message);
            Log::critical($message, ['exception' => $e]);
            return self::FAILURE;
        }

        $this->info("Partition {$tableName} created (or already existed).");

        Log::info("audit_logs partition created", [
            'partition' => $tableName,
            'from'      => $fromDate,
            'to'        => $toDate,
        ]);

        return self::SUCCESS;
    }
}
