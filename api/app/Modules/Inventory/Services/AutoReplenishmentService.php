<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Inventory\Enums\ReorderMethod;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Support\Facades\DB;

/**
 * Watches stock movements and auto-creates a draft PR for any item that crosses
 * the reorder point — unless an open PR for that item already exists.
 */
class AutoReplenishmentService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function checkAndReplenish(int $itemId): ?PurchaseRequest
    {
        /** @var Item|null $item */
        $item = Item::query()->find($itemId);
        if (! $item || ! $item->is_active) return null;

        $available = (float) $item->available;
        $reorder   = (float) $item->reorder_point;
        $safety    = (float) $item->safety_stock;

        if ($available > $reorder) return null;

        // Skip if an open auto-PR already exists for this item.
        $hasOpen = PurchaseRequest::query()
            ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
            ->whereIn('status', [
                PurchaseRequestStatus::Draft,
                PurchaseRequestStatus::Pending,
                PurchaseRequestStatus::Approved,
            ])
            ->exists();
        if ($hasOpen) return null;

        $orderQty = $this->computeOrderQuantity($item);
        $priority = $available <= $safety ? PurchaseRequestPriority::Critical : PurchaseRequestPriority::Urgent;

        return DB::transaction(function () use ($item, $orderQty, $priority) {
            $pr = PurchaseRequest::create([
                'pr_number'         => $this->sequences->generate('pr'),
                'requested_by'      => 1, // System / admin user — first user. Real impl picks from settings.
                'department_id'     => null,
                'date'              => now()->toDateString(),
                'reason'            => "Auto-generated: {$item->code} below reorder point.",
                'priority'          => $priority,
                'status'            => PurchaseRequestStatus::Draft,
                'is_auto_generated' => true,
            ]);
            PurchaseRequestItem::create([
                'purchase_request_id'  => $pr->id,
                'item_id'              => $item->id,
                'description'          => $item->name,
                'quantity'             => $orderQty,
                'unit'                 => $item->unit_of_measure,
                'estimated_unit_price' => (string) $item->standard_cost,
                'purpose'              => 'Replenish below reorder point',
            ]);
            return $pr;
        });
    }

    private function computeOrderQuantity(Item $item): string
    {
        $reorder = (float) $item->reorder_point;
        $available = (float) $item->available;
        $moq = (float) $item->minimum_order_quantity;

        if ($item->reorder_method === ReorderMethod::FixedQuantity) {
            $qty = max(($reorder * 2) - $available, $reorder);
        } else {
            // Days-of-supply: avg daily consumption (last 30d) × lead_time_days × 1.2
            $thirtyDaysAgo = now()->subDays(30);
            $totalIssued = (float) StockMovement::query()
                ->where('item_id', $item->id)
                ->whereIn('movement_type', [
                    StockMovementType::MaterialIssue->value,
                    StockMovementType::Scrap->value,
                ])
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->sum('quantity');
            $avgDaily = $totalIssued / 30.0;
            $qty = max($avgDaily * (int) $item->lead_time_days * 1.2, $reorder);
        }

        // Round up to nearest MOQ multiple.
        if ($moq > 0) {
            $qty = ceil($qty / $moq) * $moq;
        }
        return number_format(max($qty, 1.0), 3, '.', '');
    }
}
