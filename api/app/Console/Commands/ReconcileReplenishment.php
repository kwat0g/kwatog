<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Re-run bounded low-stock checks after replenishment master data is repaired. */
class ReconcileReplenishment extends Command
{
    protected $signature = 'inventory:reconcile-replenishment {--limit=100 : Maximum active items to check}';

    protected $description = 'Reconcile blocked automatic replenishment conditions';

    public function handle(AutoReplenishmentService $replenisher): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $itemType = (new Item)->getMorphClass();
        $items = Alert::query()
            ->active()
            ->where('type', AlertType::ReplenishmentBlocked->value)
            ->where('entity_type', $itemType)
            ->whereNotNull('entity_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('entity_id');
        $checked = 0;
        $errors = 0;

        foreach ($items as $itemId) {
            try {
                $replenisher->checkAndReplenish((int) $itemId);
                $checked++;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('inventory:reconcile-replenishment item failed', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$checked} replenishment item(s); limit={$limit}.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
