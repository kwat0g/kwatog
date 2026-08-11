<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemActorService;
use App\Modules\Inventory\Enums\ReorderMethod;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\LowStockPrCreated;
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
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly SystemActorService $actors,
    ) {}

    public function checkAndReplenish(int $itemId): ?PurchaseRequest
    {
        return DB::transaction(function () use ($itemId): ?PurchaseRequest {
            /** @var Item|null $item */
            $item = Item::query()
                ->lockForUpdate()
                ->find($itemId);
            if (! $item || ! $item->is_active) return null;

            $available = (float) $item->available;
            $reorder   = (float) $item->reorder_point;
            $safety    = (float) $item->safety_stock;

            if ($available > $reorder) return null;

            // Task A8 — for critical items with exactly one preferred supplier,
            // skip the PR workflow and go directly to an auto-PO routed to VP.
            if ((bool) $item->is_critical) {
                try {
                    $auto = app(\App\Modules\Purchasing\Services\AutoPurchaseOrderService::class)
                        ->createForCriticalShortage($item);
                    if ($auto !== null) {
                        return null; // PR workflow short-circuited
                    }
                } catch (\Throwable $e) {
                    // Fall through to PR workflow on any auto-PO failure, but record why.
                    \Illuminate\Support\Facades\Log::warning(
                        "AutoReplenishment: auto-PO failed for item {$item->code}, falling back to PR: {$e->getMessage()}",
                        ['item_id' => $item->id, 'exception' => $e::class]
                    );
                }
            }

            // This check runs while the item row is locked. Every low-stock
            // event for the same item therefore observes the PR/PO created by
            // the first worker before it can create another replenishment.
            $hasOpen = PurchaseRequest::query()
                ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
                ->whereIn('status', [
                    PurchaseRequestStatus::Draft,
                    PurchaseRequestStatus::Pending,
                    PurchaseRequestStatus::Approved,
                ])
                ->exists();
            if ($hasOpen) return null;

            // Auto-PRs are system-initiated; attribute only to a configured
            // automation actor. If no eligible user exists, skip rather than hit the
            // non-null requested_by FK with a bogus id.
            $systemUser = $this->actors->resolve();
            if ($systemUser === null) return null;
            $systemUserId = $systemUser->id;

            $orderQty = $this->computeOrderQuantity($item);
            if ($orderQty === null || (float) $item->standard_cost <= 0) {
                // Do not create a replenishment request with a fabricated quantity
                // or a zero-valued estimate; master data must be completed first.
                return null;
            }
            $priority = $available <= $safety ? PurchaseRequestPriority::Critical : PurchaseRequestPriority::Urgent;

            $pr = PurchaseRequest::create([
                'pr_number'         => $this->sequences->generate('pr'),
                'requested_by'      => $systemUserId,
                'department_id'     => null,
                'date'              => now()->toDateString(),
                'reason'            => "Auto-generated: {$item->code} below reorder point.",
                'priority'          => $priority,
                'is_auto_generated' => true,
            ]);
            // status non-fillable; service-only.
            $pr->forceFill(['status' => PurchaseRequestStatus::Draft])->save();
            PurchaseRequestItem::create([
                'purchase_request_id'  => $pr->id,
                'item_id'              => $item->id,
                'description'          => $item->name,
                'quantity'             => $orderQty,
                'unit'                 => $item->unit_of_measure,
                'estimated_unit_price' => (string) $item->standard_cost,
                'purpose'              => 'Replenish below reorder point',
            ]);
            app(OutboxService::class)->record(
                new LowStockPrCreated($item->fresh(), $pr->fresh()),
            );

            return $pr;
        });
    }

    private function computeOrderQuantity(Item $item): ?string
    {
        $reorder = (float) $item->reorder_point;
        $available = (float) $item->available;
        $moq = (float) $item->minimum_order_quantity;

        if ($item->reorder_method === ReorderMethod::FixedQuantity) {
            $qty = max(($reorder * 2) - $available, $reorder);
        } else {
            $historyDays = $this->settings->requiredInt('inventory.replenishment.demand_history_days', 1);
            $coverageBuffer = $this->settings->requiredFloat('inventory.replenishment.coverage_buffer_ratio', 1);
            // Days-of-supply: average demand × lead time × configured coverage buffer.
            $historyStart = now()->subDays($historyDays);
            $totalIssued = (float) StockMovement::query()
                ->where('item_id', $item->id)
                ->whereIn('movement_type', [
                    StockMovementType::MaterialIssue->value,
                    StockMovementType::Scrap->value,
                ])
                ->where('created_at', '>=', $historyStart)
                ->sum('quantity');
            $avgDaily = $totalIssued / $historyDays;
            $qty = max($avgDaily * (int) $item->lead_time_days * $coverageBuffer, $reorder);
        }

        // Round up to nearest MOQ multiple.
        if ($moq > 0) {
            $qty = ceil($qty / $moq) * $moq;
        }
        return $qty > 0 ? number_format($qty, 3, '.', '') : null;
    }
}
