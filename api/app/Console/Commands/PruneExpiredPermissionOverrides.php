<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Admin\Services\UserPermissionOverrideService;
use Illuminate\Console\Command;
use Throwable;

/**
 * H-9.2 — Audited prune path.
 *
 * Routes every expired override through UserPermissionOverrideService::remove
 * so each prune writes an audit_logs row, flushes the affected user's
 * permission cache (closing the 5-minute stale-Grant window), and broadcasts
 * a PermissionOverrideChanged event. Cron prunes are no longer silent.
 *
 * Trade-off: when run from the scheduler, Auth::id() is null inside remove()
 * and the resulting audit_logs row has user_id=NULL — the audit_logs.user_id
 * column is nullable (see 0008_create_audit_logs_table) so this is fine.
 */
class PruneExpiredPermissionOverrides extends Command
{
    protected $signature = 'overrides:prune-expired {--dry-run : Show how many would be deleted without actually deleting}';

    protected $description = 'Delete expired user permission overrides (audited).';

    public function handle(UserPermissionOverrideService $svc): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = UserPermissionOverride::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $count = $expired->count();

        if ($dryRun) {
            $this->info("Would prune {$count} expired permission override(s).");
            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($expired as $override) {
            try {
                $svc->remove($override);
            } catch (Throwable $e) {
                $errors++;
                $this->warn("Failed to remove override #{$override->id}: {$e->getMessage()}");
            }
        }

        $pruned = $count - $errors;
        $this->info("Pruned {$pruned} expired permission override(s).");

        if ($errors > 0) {
            $this->warn("{$errors} override(s) failed to remove (see warnings above).");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
