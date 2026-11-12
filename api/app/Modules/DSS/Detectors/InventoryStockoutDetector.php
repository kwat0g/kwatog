<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** D28 — zero-stock and replenishment-threshold recommendations by item. */
final class InventoryStockoutDetector implements DetectorInterface
{
    public const ID = 'dss.inventory_stockout';

    public function __construct(private readonly StockLocationSummaryService $locations) {}

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'inventory';
    }

    public function ownerPermissions(): array
    {
        return ['inventory.view', 'production.work_orders.view', 'purchasing.pr.create'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, min(10000, (int) ($context['limit'] ?? 200)));
        $items = Item::query()
            ->with('stockLevels.location.zone.warehouse')
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->where('reorder_point', '>', 0)
                    ->orWhere('safety_stock', '>', 0)
                    ->orWhere(function (Builder $finishedGoods): void {
                        $finishedGoods->where('item_type', ItemType::FinishedGood->value)
                            ->whereExists(static fn ($products) => $products->selectRaw('1')
                                ->from('products')
                                ->whereColumn('products.part_number', 'items.code')
                                ->where('products.is_active', true)
                                ->whereNull('products.deleted_at'));
                    });
            })
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $complete = $items->count() <= $limit;
        $items = $items->take($limit)->values();
        $stockLevels = $items->flatMap(static fn (Item $item): Collection => $item->stockLevels);
        $availability = $stockLevels->isEmpty()
            ? collect()
            : $this->locations->availabilityByItem($stockLevels);

        $emissions = [];
        foreach ($items as $item) {
            /** @var Item $item */
            $itemType = ItemType::tryFrom((string) $item->getRawOriginal('item_type'));
            if ($itemType === null) {
                continue;
            }

            $position = $availability->get((int) $item->id, []);
            $usable = (string) ($position['usable_quantity'] ?? '0.000');
            $reorderPoint = (string) ($item->reorder_point ?? '0.000');
            $safetyStock = (string) ($item->safety_stock ?? '0.000');
            $threshold = bccomp($reorderPoint, '0', 3) > 0 ? $reorderPoint : $safetyStock;
            $hasThreshold = bccomp($threshold, '0', 3) > 0;
            $thresholdSource = bccomp($reorderPoint, '0', 3) > 0
                ? 'reorder_point'
                : ($hasThreshold ? 'safety_stock' : 'finished_good_stockout');
            $isStockout = bccomp($usable, '0', 3) <= 0;

            if (! $isStockout && (! $hasThreshold || bccomp($usable, $threshold, 3) > 0)) {
                continue;
            }

            $isFinishedGood = $itemType === ItemType::FinishedGood;
            $path = $isFinishedGood ? 'production' : 'purchasing_review';
            $rule = $isStockout ? 'zero_usable_stock' : 'usable_stock_at_or_below_threshold';
            $shortage = bccomp($threshold, $usable, 3) > 0
                ? bcsub($threshold, $usable, 3)
                : '0.000';
            $why = $isFinishedGood
                ? 'No usable finished-goods stock remains. Review production planning; this recommendation does not create a supplier purchase request.'
                : 'Usable stock is at or below its configured replenishment threshold. Review purchasing and incoming supply before acting.';

            $emissions[] = new DetectorEmission(
                type: 'inventory_stockout',
                severity: $isStockout ? 'critical' : 'warning',
                dedupeKey: 'stockout:item:'.$item->id,
                rationale: [
                    'rule' => $rule,
                    'item_code' => $item->code,
                    'item_name' => $item->name,
                    'item_type' => $itemType->value,
                    'on_hand_quantity' => (string) ($position['on_hand_quantity'] ?? '0.000'),
                    'reserved_quantity' => (string) ($position['reserved_quantity'] ?? '0.000'),
                    'usable_quantity' => $usable,
                    'reorder_point' => $reorderPoint,
                    'safety_stock' => $safetyStock,
                    'active_threshold' => $hasThreshold ? $threshold : null,
                    'threshold_source' => $thresholdSource,
                    'shortage_quantity' => $shortage,
                    'replenishment_path' => $path,
                    'why_it_matters' => $why,
                ],
                subjectType: Item::class,
                subjectId: (int) $item->id,
                confidence: 1.0,
                dataSufficiency: 'partial',
                action: [
                    'route' => $isFinishedGood ? '/production/work-orders' : '/purchasing/purchase-requests',
                    'label' => $isFinishedGood ? 'Review production →' : 'Review purchase requests →',
                ],
                ownerPermissions: [$isFinishedGood ? 'production.work_orders.view' : 'purchasing.pr.create'],
                decisionCode: 'inventory_stockout',
                policyVersion: $this->version(),
            );
        }

        return new DetectorScanResult($emissions, $items->count(), $complete);
    }
}
