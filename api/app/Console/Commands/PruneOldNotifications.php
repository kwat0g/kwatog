<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=90 : Delete read notifications older than N days}';
    protected $description = 'Delete read notifications older than the specified number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} read notifications older than {$days} days.");

        return self::SUCCESS;
    }
}
