<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\BackupService;
use Illuminate\Console\Command;

class RollbackBackupOperation extends Command
{
    protected $signature = 'backup:rollback {operationId : UUID of a rollback-required restore operation}';

    protected $description = 'Restore the pre-restore rollback point for a failed backup restore';

    public function handle(BackupService $backups): int
    {
        $backups->rollback((string) $this->argument('operationId'));
        $this->info('Backup restore rollback completed and the application gate was released.');

        return self::SUCCESS;
    }
}
