<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\RecommendationService;
use Illuminate\Console\Command;

class MaintainDss extends Command
{
    protected $signature = 'dss:maintain';

    protected $description = 'Expire stale DSS recommendations and prune old detector run logs';

    public function handle(RecommendationService $recommendations): int
    {
        $expired = $recommendations->expirePastValidity();
        $pruned = DssRunLog::query()->where('created_at', '<', now()->subDays(90))->delete();

        $this->info("Expired {$expired} recommendations; pruned {$pruned} run logs older than 90 days.");

        return self::SUCCESS;
    }
}
