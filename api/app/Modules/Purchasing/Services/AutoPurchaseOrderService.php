<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Services\ApprovalService;
use App\Common\Services\AlertEngineService;
use App\Common\Services\BusinessPolicyService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task A8 — For items marked is_critical = true with a single preferred
 * approved supplier, auto-create a PO directly (bypasses the 4-level PR
 * workflow). The PO enters the canonical purchase-order approval workflow.
 *
 * Returns the created PO, or null if criteria are not met (caller should
 * fall back to the normal PR workflow).
 */
class AutoPurchaseOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ApprovalService $approvals,
        private readonly AlertEngineService $alerts,
        private readonly TaxPolicyService $taxPolicy,
        private readonly SettingsService $settings,
        private readonly BusinessPolicyService $businessPolicy,
    ) {}

    public function createForCriticalShortage(Item $item): ?PurchaseOrder
    {
        return DB::transaction(function () use ($item): ?PurchaseOrder {
            /** @var Item|null $lockedItem */
            $lockedItem = Item::query()->lockForUpdate()->find($item->id);
            if (! $lockedItem || ! (bool) $lockedItem->is_critical) return null;
            $item = $lockedItem;

            $onHand = (float) DB::table('stock_levels')
                ->where('item_id', $item->id)
                ->sum('quantity');
            $reorder = (float) $item->reorder_point;
            if ($onHand >= $reorder || $reorder <= 0) return null;

            // Exactly one preferred supplier. Lock the choice together with the
            // item so concurrent replenishment workers cannot create two auto-POs
            // from a supplier change in the same shortage window.
            $preferred = ApprovedSupplier::query()
                ->qualified()
                ->where('item_id', $item->id)
                ->where('is_preferred', true)
                ->lockForUpdate()
                ->get();
            if ($preferred->count() !== 1) return null;

            $supplier = $preferred->first();

            // Idempotency: skip if there's already an open auto-PO for this item.
            $hasOpenAuto = PurchaseOrder::query()
                ->where('is_auto_generated', true)
                ->whereIn('status', [
                    PurchaseOrderStatus::Draft->value,
                    PurchaseOrderStatus::PendingApproval->value,
                    PurchaseOrderStatus::Approved->value,
                ])
                ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
                ->exists();
            if ($hasOpenAuto) return null;

            $qty = $reorder + (float) $item->safety_stock - $onHand;
            if ($qty <= 0) return null;
            $quantity = number_format($qty, 2, '.', '');
            $price = (string) ($supplier->last_price ?? $item->standard_cost ?? '0.00');
            // An auto-generated PO must never invent a zero price. Wait for an
            // authoritative supplier/item cost and let the normal procurement
            // flow handle the shortage when no price exists.
            if (Money::lte($price, '0')) return null;
            $leadTimeDays = (int) ($supplier->lead_time_days ?? 0);
            if ($leadTimeDays <= 0) $leadTimeDays = (int) ($item->lead_time_days ?? 0);
            if ($leadTimeDays <= 0) {
                $leadTimeDays = $this->settings->requiredInt('mrp.default_lead_time_days', 0, 365);
            }
            $sub = Money::mul($quantity, $price);
            $isVatable = $this->taxPolicy->isVatRegistered();
            $vat = $isVatable
                ? Money::mul($sub, (string) $this->taxPolicy->requiredVatRate())
                : Money::zero();
            // PU-10 — the chip is display/filter-only (the real gate is the
            // workflow step threshold) but must not contradict it: a sub-threshold
            // auto-PO claimed VP approval was required while its VP step was
            // skipped. Same computation as PurchaseOrderService::create.
            $requiresVp = Money::gte(
                Money::add($sub, $vat),
                (string) $this->businessPolicy->purchaseOrderVpThreshold(),
            );

            $po = PurchaseOrder::create([
                'po_number'             => $this->sequences->generate('purchase_order'),
                'vendor_id'             => $supplier->vendor_id,
                'purchase_request_id'   => null,
                'date'                  => Carbon::today(),
                'expected_delivery_date'=> Carbon::today()->addDays((int) $leadTimeDays),
                'subtotal'              => $sub,
                'vat_amount'            => $vat,
                'total_amount'          => Money::add($sub, $vat),
                'is_vatable'            => $isVatable,
                'requires_vp_approval'  => $requiresVp,
                'created_by'            => null,
                'remarks'               => "Auto-generated for critical stock alert on {$item->code}.",
                'is_auto_generated'     => true,
            ]);
            // Use the canonical PO lifecycle. ApprovalService owns the
            // durable approval records; `pending_vp` was never a valid enum or
            // database value and made this path fail before approval began.
            $po->forceFill([
                'status'                => PurchaseOrderStatus::PendingApproval,
                'current_approval_step' => 0,
            ])->save();

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id'           => $item->id,
                'description'       => $item->name,
                'quantity'          => $quantity,
                'unit'              => $item->unit_of_measure,
                'unit_price'        => $price,
                'total'             => $sub,
                'quantity_received' => 0,
            ]);

            $this->approvals->submit($po, 'purchase_order', (string) $po->total_amount);

            // Raise an alert for visibility on the dashboard.
            $this->alerts->raise(
                AlertType::StockCritical,
                AlertSeverity::Critical,
                "Auto-PO created: {$item->code}",
                "Auto-PO {$po->po_number} created for {$item->name}. Awaiting approval.",
                $item,
                ['po_id' => $po->id, 'po_number' => $po->po_number, 'qty' => $quantity],
            );

            // Notify the role owning the first canonical approval step. The
            // old implementation notified a VP even though the PO workflow
            // starts with Purchasing and Finance.
            $this->notifyApprovers($po, $item);

            return $po;
        });
    }

    private function notifyApprovers(PurchaseOrder $po, Item $item): void
    {
        try {
            $next = $this->approvals->nextStep($po);
            $roles = $next ? [$next->role_slug] : array_values(array_filter(
                (array) $this->settings->get('purchasing.auto_po.approval_roles', []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            $vps = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();
            app(NotificationService::class)->send($vps, 'auto_po_pending', [
                'title'       => 'Auto-PO awaiting approval',
                'message'     => "Critical stock alert. Auto-PO {$po->po_number} for {$item->code} is awaiting approval.",
                'link_to'     => "/purchasing/purchase-orders/{$po->hash_id}",
                'entity_type' => 'purchase_order',
                'entity_id'   => $po->hash_id,
                'po_number'   => $po->po_number,
                'item_code'   => $item->code,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AutoPurchaseOrderService::notifyApprovers failed', ['error' => $e->getMessage()]);
        }
    }
}
