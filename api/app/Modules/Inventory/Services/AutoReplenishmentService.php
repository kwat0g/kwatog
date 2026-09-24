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
use App\Modules\Purchasing\Services\OpenSupplyService;
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
        private readonly OpenSupplyService $openSupply,
    ) {}

    public function checkAndReplenish(int $itemId): ?PurchaseRequest
    {
        return DB::transaction(function () use ($itemId): ?PurchaseRequest {
            /** @var Item|null $item */
            $item = Item::query()
                ->lockForUpdate()
                ->with('stockLevels')
                ->find($itemId);
            if (! $item || ! $item->is_active) return null;

            $available = (string) $item->available;
            $reorder   = (string) $item->reorder_point;
            $safety    = (string) $item->safety_stock;

            // Net in-transit supply from open POs to position.
            $inTransit = $this->openSupply->inTransitBaseQuantity($item->id);
            $position = bcadd($available, $inTransit, 3);

            if (bccomp($position, $reorder, 3) > 0) return null;

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
            // Note: in-transit supply is already netted via position; this guard
            // prevents duplicate PRs from the same low-stock event.
            // Count PRs with remaining unconverted quantity, including Draft (not yet submitted).
            // Fully converted Approved PRs should not block a new replenishment.
            if (bccomp($this->openSupply->openRequestBaseQuantity($item->id, null, false, true), '0', 3) > 0) {
                return null;
            }

            // Auto-PRs are system-initiated; attribute only to a configured
            // automation actor. If no eligible user exists, skip rather than hit the
            // non-null requested_by FK with a bogus id.
            $systemUser = $this->actors->resolve();
            if ($systemUser === null) return null;
            $systemUserId = $systemUser->id;

            $orderQty = $this->computeOrderQuantity($item, $position);
            if ($orderQty === null || bccomp((string) $item->standard_cost, '0', 2) <= 0) {
                // Do not create a replenishment request with a fabricated quantity
                // or a zero-valued estimate; master data must be completed first.
                return null;
            }
            $priority = bccomp($position, $safety, 3) <= 0
                ? PurchaseRequestPriority::Critical
                : PurchaseRequestPriority::Urgent;

            $leadTime = (int) ($item->lead_time_days ?? 0);
            if ($leadTime <= 0) {
                $leadTime = $this->settings->requiredInt('mrp.default_lead_time_days', 0, 365);
            }
            $requiredDeliveryDate = $leadTime > 0 ? now()->addDays($leadTime)->toDateString() : null;

            $pr = PurchaseRequest::create([
                'pr_number'         => $this->sequences->generate('pr'),
                'requested_by'      => $systemUserId,
                'department_id'     => null,
                'date'              => now()->toDateString(),
                'required_delivery_date' => $requiredDeliveryDate,
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

    private function computeOrderQuantity(Item $item, string $position): ?string
    {
        $reorder = (string) $item->reorder_point;
        $moq = (string) $item->minimum_order_quantity;

        if ($item->reorder_method === ReorderMethod::FixedQuantity) {
            // Use position (available + in-transit) so the target reflects supply already in flight.
            $target = bcsub(bcmul($reorder, '2', 3), $position, 3);
            $qty = bccomp($target, $reorder, 3) > 0 ? $target : $reorder;
        } else {
            $historyDays = $this->settings->requiredInt('inventory.replenishment.demand_history_days', 1);
            $coverageBuffer = number_format(
                $this->settings->requiredFloat('inventory.replenishment.coverage_buffer_ratio', 1),
                6,
                '.',
                '',
            );
            // Days-of-supply: average demand × lead time × configured coverage buffer.
            $historyStart = now()->subDays($historyDays);
            $totalIssued = (string) StockMovement::query()
                ->where('item_id', $item->id)
                ->whereIn('movement_type', [
                    StockMovementType::MaterialIssue->value,
                    StockMovementType::Scrap->value,
                ])
                ->where('created_at', '>=', $historyStart)
                ->sum('quantity');
            $avgDaily = bcdiv($totalIssued, (string) $historyDays, 6);
            $demand = bcmul(
                bcmul($avgDaily, (string) (int) $item->lead_time_days, 6),
                $coverageBuffer,
                6,
            );
            $qty = bccomp($demand, $reorder, 3) > 0 ? $demand : $reorder;
        }

        // Round up to nearest MOQ multiple.
        if (bccomp($moq, '0', 3) > 0) {
            $multiples = bcdiv($qty, $moq, 6);
            $whole = bcdiv($multiples, '1', 0);
            if (bccomp($multiples, $whole, 6) > 0) {
                $whole = bcadd($whole, '1', 0);
            }
            $qty = bcmul($whole, $moq, 3);
        }
        return bccomp($qty, '0', 3) > 0 ? bcadd($qty, '0', 3) : null;
    }
}
