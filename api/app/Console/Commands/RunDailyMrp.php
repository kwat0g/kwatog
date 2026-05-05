<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Services\MrpEngineService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Task A1 — Daily MRP run, scheduled at 06:00 in routes/console.php.
 */
class RunDailyMrp extends Command
{
    protected $signature   = 'mrp:run-daily';
    protected $description = 'Re-run MRP across all active sales orders (Task A1)';

    public function handle(MrpEngineService $engine): int
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

        // Notify PPC Head role.
        try {
            $ppcHeads = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'ppc_head'))
                ->where('is_active', true)
                ->get();

            foreach ($ppcHeads as $user) {
                $user->notifications()->create([
                    'id'            => (string) \Illuminate\Support\Str::uuid(),
                    'type'          => 'mrp_run_completed',
                    'notifiable_type' => $user::class,
                    'notifiable_id' => $user->id,
                    'data'          => [
                        'run_id'         => $run->hash_id,
                        'shortages_found'=> $run->shortages_found,
                        'prs_created'    => $run->prs_created,
                        'prs_updated'    => $run->prs_updated,
                        'plans_generated'=> $run->plans_generated,
                        'message'        => "Daily MRP complete. {$run->shortages_found} shortages found. {$run->prs_created} PRs created, {$run->prs_updated} updated.",
                    ],
                    'read_at'       => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('mrp:run-daily — notify failed', ['error' => $e->getMessage()]);
        }

        return $run->status?->value === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
