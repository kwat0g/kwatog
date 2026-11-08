<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Events\NotificationsPruned;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=90 : Delete read notifications older than N days}';
    protected $description = 'Delete read notifications older than the specified number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $runId = (string) Str::uuid();
        $deleted = 0;
        $recipientsByType = [
            User::class => 'internal',
            CustomerPortalUser::class => 'customer',
            SupplierPortalUser::class => 'supplier',
        ];

        foreach ($recipientsByType as $notifiableType => $realm) {
            $batchNumber = 0;
            DB::table('notifications')
                ->where('notifiable_type', $notifiableType)
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->select('notifiable_id')
                ->distinct()
                ->orderBy('notifiable_id')
                ->chunkById(50, function ($recipients) use (
                    $cutoff,
                    $notifiableType,
                    $realm,
                    $runId,
                    &$batchNumber,
                    &$deleted,
                ): void {
                    $userIds = $recipients->pluck('notifiable_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
                    if ($userIds === []) {
                        return;
                    }

                    $deleted += DB::transaction(function () use (
                        $cutoff,
                        $notifiableType,
                        $realm,
                        $runId,
                        $batchNumber,
                        $userIds,
                    ): int {
                        $count = DB::table('notifications')
                            ->where('notifiable_type', $notifiableType)
                            ->whereIn('notifiable_id', $userIds)
                            ->whereNotNull('read_at')
                            ->where('created_at', '<', $cutoff)
                            ->delete();

                        if ($count > 0) {
                            app(OutboxService::class)->recordCoalesced(
                                new NotificationsPruned($realm, $userIds),
                                "notifications-prune:{$runId}:{$realm}:{$batchNumber}",
                            );
                        }

                        return $count;
                    });

                    $batchNumber++;
                }, 'notifiable_id', 'notifiable_id');
        }

        // Preserve the original cleanup behavior for any future notifiable
        // type, even though current app inboxes use the three known realms.
        $deleted += DB::table('notifications')
            ->whereNotIn('notifiable_type', array_keys($recipientsByType))
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} read notifications older than {$days} days.");

        return self::SUCCESS;
    }
}
