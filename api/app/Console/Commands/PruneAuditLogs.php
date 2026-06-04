<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--months=12 : Retain logs for this many months}';
    protected $description = 'Delete audit logs older than the retention period';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $cutoff = now()->subMonths($months)->startOfDay();

        $deleted = DB::table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} audit log records older than {$months} months.");

        return self::SUCCESS;
    }
}
