<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
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

        // Auto-PRs are system-initiated; attribute to a system_admin (fallback:
        // first user). If no user exists at all, skip rather than hit the
        // non-null requested_by FK with a bogus id.
        $systemUserId = $this->systemUserId();
        if ($systemUserId === null) return null;

        $orderQty = $this->computeOrderQuantity($item);
        $priority = $available <= $safety ? PurchaseRequestPriority::Critical : PurchaseRequestPriority::Urgent;

        $pr = DB::transaction(function () use ($item, $orderQty, $priority, $systemUserId) {
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
            return $pr;
        });

        event(new LowStockPrCreated($item, $pr));

        return $pr;
    }

    /**
     * Resolve the user an auto-generated PR is attributed to: a system_admin
     * if one exists, else the lowest user id. Null only when there are no users.
     */
    private function systemUserId(): ?int
    {
        $adminId = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
            ->min('id');

        return $adminId !== null ? (int) $adminId : (User::query()->min('id') !== null ? (int) User::query()->min('id') : null);
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
