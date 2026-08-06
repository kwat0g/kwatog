<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Services\MrpEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Task A1 — Daily MRP run, scheduled at 06:00 in routes/console.php.
 */
class RunDailyMrp extends Command
{
    protected $signature   = 'mrp:run-daily';
    protected $description = 'Re-run MRP across all active sales orders (Task A1)';

    public function handle(MrpEngineService $engine, SettingsService $settings): int
    {
        $this->info('Starting daily MRP run...');

        $run = $engine->runForAllActiveSalesOrders(MrpRunTrigger::Scheduled, null);

        $this->info(sprintf(
            'Daily MRP run %s — evaluated %d SOs, %d shortages, %d PRs created, %d PRs updated, %dms',
            $run->status?->value ?? 'unknown',
            $run->sales_orders_evaluated,
            $run->shortages_found,
            $run->prs_created,
            $run->prs_updated,
            $run->duration_ms ?? 0,
        ));

        // Notify configured MRP audience.
        try {
            $roles = array_values(array_filter((array) $settings->get('mrp.daily_run.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $ppcHeads = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            app(NotificationService::class)->send($ppcHeads, 'mrp_run_completed', [
                'title'           => 'Daily MRP run finished',
                'message'         => "Daily MRP complete. {$run->shortages_found} shortages found. {$run->prs_created} PRs created, {$run->prs_updated} updated.",
                'link_to'         => "/mrp/runs/{$run->hash_id}",
                'entity_type'     => 'mrp_run',
                'entity_id'       => $run->hash_id,
                'shortages_found' => $run->shortages_found,
                'prs_created'     => $run->prs_created,
                'prs_updated'     => $run->prs_updated,
                'plans_generated' => $run->plans_generated,
            ]);        } catch (\Throwable $e) {
            Log::warning('mrp:run-daily — notify failed', ['error' => $e->getMessage()]);
        }

        return $run->status?->value === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
