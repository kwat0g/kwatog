<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\ThreeWayMatchResult;
use Illuminate\Support\Facades\Log;

/**
 * Variance check gating AP bill approval.
 *
 * Three-way (PO ↔ Bill ↔ GRN). PO-vs-Bill tolerances (qty + price) are
 * settings-driven (`purchasing.three_way_tolerance_qty_pct`,
 * `purchasing.three_way_tolerance_price_pct`). On top of that, Bill qty
 * must not exceed accepted GRN qty by more than the qty tolerance —
 * i.e. you cannot pay for goods that were never received. Override is
 * still available via BillService::create($data + allow_override=true)
 * which bypasses the blocked status (audit trail in three_way_match_snapshot).
 *
 * H-7 + H-6 (2026-06): matchForBill aligns bill lines to PO lines by item_id
 * FK (not by index). Legacy bills without item_id on any line fall back to
 * the old index-based alignment with a logged warning.
 */
class ThreeWayMatchService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function matchForPo(PurchaseOrder $po, array $billLines): ThreeWayMatchResult
    {
        $po->loadMissing('items');

        $qtyTol   = (float) $this->settings->get('purchasing.three_way_tolerance_qty_pct', 5.0);
        $priceTol = (float) $this->settings->get('purchasing.three_way_tolerance_price_pct', 5.0);

        // Aggregate accepted GRN qty per po_item.
        $grnAccepted = GrnItem::query()
            ->whereIn('purchase_order_item_id', $po->items->pluck('id'))
            ->select('purchase_order_item_id',
                \DB::raw('SUM(quantity_accepted) as qty_accepted'),
                \DB::raw('AVG(unit_cost) as avg_cost')
            )
            ->groupBy('purchase_order_item_id')
            ->get()
            ->keyBy('purchase_order_item_id');

        // Index bill lines by item_id (or by description fallback).
        $billByItem = [];
        foreach ($billLines as $bl) {
            $key = isset($bl['item_id']) && $bl['item_id'] ? (string) $bl['item_id'] : 'desc:'.($bl['description'] ?? '');
            $billByItem[$key] = $bl;
        }

        $lines = [];
        $overall = 'matched';
        foreach ($po->items as $poi) {
            $grn = $grnAccepted[$poi->id] ?? null;
            $billKey = (string) $poi->item_id;
            $bl = $billByItem[$billKey] ?? null;

            $billQty   = $bl ? (float) $bl['quantity']   : 0.0;
            $billPrice = $bl ? (float) $bl['unit_price'] : 0.0;
            $grnQty    = $grn ? (float) $grn->qty_accepted : 0.0;
            $grnCost   = $grn ? (float) $grn->avg_cost     : (float) $poi->unit_price;
            $poQty     = (float) $poi->quantity;
            $poPrice   = (float) $poi->unit_price;

            $qtyVar   = $poQty   > 0 ? abs($billQty - $poQty)   / $poQty   * 100 : 0.0;
            $priceVar = $poPrice > 0 ? abs($billPrice - $poPrice) / $poPrice * 100 : 0.0;

            $qtyOk   = $qtyVar   <= $qtyTol;
            $priceOk = $priceVar <= $priceTol;

            // H-6 — Bill qty must not exceed accepted GRN qty beyond the qty
            // tolerance. If there is no GRN at all, any non-zero bill qty is
            // a hard block — you cannot pay for goods that were never received.
            if ($grnQty > 0) {
                $grnOverPct = ($billQty - $grnQty) / max($grnQty, 0.0001) * 100;
                $grnOk = $grnOverPct <= $qtyTol;
            } else {
                $grnOk = $billQty <= 0;
            }

            $severity = ($qtyOk && $priceOk && $grnOk) ? 'ok' : 'block';
            $lineStatus = match (true) {
                ! $qtyOk && ! $priceOk => 'both',
                ! $qtyOk               => 'qty_variance',
                ! $priceOk             => 'price_variance',
                ! $grnOk               => 'grn_short',
                default                => 'matched',
            };

            if ($severity === 'block') $overall = 'blocked';
            elseif (($qtyVar > 0 || $priceVar > 0) && $overall !== 'blocked') $overall = 'has_variances';

            $lines[] = [
                'item_id'           => $poi->item_id,
                'item_code'         => $poi->item?->code,
                'description'       => $poi->description,
                'po_quantity'       => number_format($poQty, 2, '.', ''),
                'po_unit_price'     => number_format($poPrice, 2, '.', ''),
                'po_total'          => number_format($poQty * $poPrice, 2, '.', ''),
                'grn_quantity_accepted' => number_format($grnQty, 3, '.', ''),
                'grn_unit_cost'     => number_format($grnCost, 4, '.', ''),
                'grn_status'        => $grnOk ? 'ok' : 'short',
                'bill_quantity'     => number_format($billQty, 2, '.', ''),
                'bill_unit_price'   => number_format($billPrice, 2, '.', ''),
                'bill_total'        => number_format($billQty * $billPrice, 2, '.', ''),
                'quantity_variance_pct' => round($qtyVar, 2),
                'price_variance_pct'    => round($priceVar, 2),
                'status'   => $lineStatus,
                'severity' => $severity,
            ];
        }

        return new ThreeWayMatchResult(
            poId: $po->id,
            poNumber: $po->po_number,
            lines: $lines,
            overallStatus: $overall,
            tolerances: ['qty_pct' => $qtyTol, 'price_pct' => $priceTol],
        );
    }

    public function matchForBill(Bill $bill): ?ThreeWayMatchResult
    {
        if (! $bill->purchase_order_id) return null;
        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->with('items.item')->findOrFail($bill->purchase_order_id);

        $bill->loadMissing('items');

        // H-7: Prefer FK alignment — index alignment is unsafe when bill lines
        // are skipped or reordered relative to the PO.
        $billLinesByItem = [];
        $anyHasItemId = false;
        foreach ($bill->items as $bi) {
            if ($bi->item_id) {
                $anyHasItemId = true;
                $billLinesByItem[(string) $bi->item_id] = [
                    'item_id'     => $bi->item_id,
                    'description' => $bi->description,
                    'quantity'    => $bi->quantity,
                    'unit_price'  => $bi->unit_price,
                ];
            }
        }

        if ($anyHasItemId) {
            return $this->matchForPo($po, array_values($billLinesByItem));
        }

        // Legacy fallback: bill predates H-7 backfill (all item_ids NULL).
        // Best-effort index alignment, with a logged warning so we can spot
        // any rows the migration could not backfill.
        Log::warning('ThreeWayMatchService::matchForBill falling back to index alignment', [
            'bill_id' => $bill->id,
            'po_id'   => $po->id,
        ]);

        $billLines = $bill->items->map(fn ($i) => [
            'item_id'     => null,
            'description' => $i->description,
            'quantity'    => $i->quantity,
            'unit_price'  => $i->unit_price,
        ])->all();

        $aligned = [];
        foreach ($po->items as $idx => $poi) {
            if (isset($billLines[$idx])) {
                $aligned[(string) $poi->item_id] = $billLines[$idx];
            }
        }
        return $this->matchForPo($po, array_values($aligned));
    }
}
