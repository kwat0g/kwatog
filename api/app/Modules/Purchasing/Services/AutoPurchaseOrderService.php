<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Services\AlertEngineService;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Task A8 — For items marked is_critical = true with a single preferred
 * approved supplier, auto-create a PO directly (bypasses the 4-level PR
 * workflow). The PO is routed to VP for one-step approval.
 *
 * Returns the created PO, or null if criteria are not met (caller should
 * fall back to the normal PR workflow).
 */
class AutoPurchaseOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AlertEngineService $alerts,
    ) {}

    public function createForCriticalShortage(Item $item): ?PurchaseOrder
    {
        if (! (bool) $item->is_critical) return null;

        $onHand = (float) DB::table('stock_levels')
            ->where('item_id', $item->id)
            ->sum('quantity');
        $reorder = (float) $item->reorder_point;
        if ($onHand >= $reorder || $reorder <= 0) return null;

        // Exactly one preferred supplier.
        $preferred = ApprovedSupplier::where('item_id', $item->id)
            ->where('is_preferred', true)
            ->get();
        if ($preferred->count() !== 1) return null;

        $supplier = $preferred->first();

        // Idempotency: skip if there's already an open auto-PO for this item.
        $hasOpenAuto = PurchaseOrder::query()
            ->where('is_auto_generated', true)
            ->whereIn('status', ['draft', 'pending_vp', 'pending', 'approved'])
            ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
            ->exists();
        if ($hasOpenAuto) return null;

        $qty   = max(1.0, $reorder + (float) $item->safety_stock - $onHand);
        $price = (float) ($supplier->last_price ?? $item->standard_cost ?? 0);
        $sub   = round($qty * $price, 2);
        $vat   = round($sub * 0.12, 2);

        return DB::transaction(function () use ($item, $supplier, $qty, $price, $sub, $vat) {
            $po = PurchaseOrder::create([
                'po_number'             => $this->sequences->generate('po'),
                'vendor_id'             => $supplier->vendor_id,
                'purchase_request_id'   => null,
                'date'                  => Carbon::today(),
                'expected_delivery_date'=> Carbon::today()->addDays((int) $supplier->lead_time_days),
                'subtotal'              => $sub,
                'vat_amount'            => $vat,
                'total_amount'          => $sub + $vat,
                'is_vatable'            => true,
                'status'                => 'pending_vp',
                'requires_vp_approval'  => true,
                'current_approval_step' => 1,
                'created_by'            => null,
                'remarks'               => "Auto-generated for critical stock alert on {$item->code}.",
                'is_auto_generated'     => true,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id'           => $item->id,
                'description'       => $item->name,
                'quantity'          => round($qty, 2),
                'unit'              => $item->unit_of_measure,
                'unit_price'        => $price,
                'total'             => $sub,
                'quantity_received' => 0,
            ]);

            // Raise an alert for visibility on the dashboard.
            $this->alerts->raise(
                AlertType::StockCritical,
                AlertSeverity::Critical,
                "Auto-PO created: {$item->code}",
                "Auto-PO {$po->po_number} created for {$item->name}. Awaiting VP approval.",
                $item,
                ['po_id' => $po->id, 'po_number' => $po->po_number, 'qty' => $qty],
            );

            // Notify VP (system_admin / production_manager fallback) directly.
            $this->notifyVp($po, $item);

            return $po;
        });
    }

    private function notifyVp(PurchaseOrder $po, Item $item): void
    {
        try {
            $vps = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['system_admin', 'production_manager']))
                ->where('is_active', true)
                ->get();
            foreach ($vps as $u) {
                $u->notifications()->create([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'auto_po_pending',
                    'notifiable_type' => $u::class,
                    'notifiable_id'   => $u->id,
                    'data'            => [
                        'po_id'     => $po->hash_id,
                        'po_number' => $po->po_number,
                        'item_code' => $item->code,
                        'message'   => "Critical stock alert. Auto-PO {$po->po_number} for {$item->code}. Review and approve.",
                        'link'      => "/purchasing/purchase-orders/{$po->hash_id}",
                    ],
                    'read_at'         => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AutoPurchaseOrderService::notifyVp failed', ['error' => $e->getMessage()]);
        }
    }
}
