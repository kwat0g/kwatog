<?php

declare(strict_types=1);

namespace App\Common\Jobs;

use App\Common\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $operationId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('ogami-backup-recovery')];
    }

    public function handle(BackupService $backups): void
    {
        $backups->runBackup($this->operationId);
    }

    public function failed(Throwable $exception): void
    {
        app(BackupService::class)->markFailed($this->operationId, $exception);
    }
}
