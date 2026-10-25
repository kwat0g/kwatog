<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\RecommendationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainDss extends Command
{
    protected $signature = 'dss:maintain';

    protected $description = 'Expire stale DSS recommendations and prune old detector run logs';

    public function handle(RecommendationService $recommendations): int
    {
        $expired = $recommendations->expirePastValidity();
        $interrupted = 0;
        DssRunLog::query()->where('status', 'running')->where('started_at', '<', now()->subSeconds(310))
            ->orderBy('id')->chunkById(100, function ($runs) use (&$interrupted): void {
                foreach ($runs as $run) {
                    $interrupted += DB::transaction(function () use ($run): int {
                        $lockedRun = DssRunLog::query()->whereKey($run->id)->lockForUpdate()->first();
                        if ($lockedRun === null || $lockedRun->status !== 'running'
                            || $lockedRun->started_at->gte(now()->subSeconds(310))) {
                            return 0;
                        }

                        $lockedRun->fill([
                            'status' => 'failed',
                            'finished_at' => now(),
                            'error' => DssRunLog::INTERRUPTION_REASON,
                        ])->save();

                        return 1;
                    });
                }
            });
        $pruned = DssRunLog::query()->where('created_at', '<', now()->subDays(90))->delete();

        $this->info("Expired {$expired} recommendations; failed {$interrupted} interrupted runs; pruned {$pruned} run logs older than 90 days.");

        return self::SUCCESS;
    }
}
