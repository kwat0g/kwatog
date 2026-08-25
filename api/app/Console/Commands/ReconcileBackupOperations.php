<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\BackupService;
use Illuminate\Console\Command;

class ReconcileBackupOperations extends Command
{
    protected $signature = 'backup:reconcile-stale';

    protected $description = 'Reconcile queued or running backup operations whose lease expired';

    public function handle(BackupService $backups): int
    {
        $count = $backups->reconcileStaleOperations();
        $this->info("Reconciled {$count} stale backup operation(s).");

        return self::SUCCESS;
    }
}
