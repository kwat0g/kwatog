<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Services\SafetyStockRecomputeService;
use Illuminate\Console\Command;

class RecomputeSafetyStock extends Command
{
    protected $signature = 'inventory:recompute-safety-stock';

    protected $description = 'T1.4 — Recompute items.safety_stock from recent issue history (Z × σ × √lead_time).';

    public function handle(SafetyStockRecomputeService $service): int
    {
        $result = $service->recomputeAll();
        $this->info(sprintf(
            'safety-stock recompute: evaluated=%d updated=%d skipped=%d',
            $result['evaluated'], $result['updated'], $result['skipped'],
        ));
        return self::SUCCESS;
    }
}
