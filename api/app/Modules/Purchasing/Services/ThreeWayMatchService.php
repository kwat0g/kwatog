<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\ThreeWayMatchResult;

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
            $severity = ($qtyOk && $priceOk) ? 'ok' : 'block';
            $lineStatus = $qtyOk && $priceOk
                ? 'matched'
                : (! $qtyOk && ! $priceOk ? 'both' : (! $qtyOk ? 'qty_variance' : 'price_variance'));

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
        $billLines = $bill->items->map(fn ($i) => [
            'item_id'     => null, // bills don't store item_id directly; matched via PO line
            'description' => $i->description,
            'quantity'    => $i->quantity,
            'unit_price'  => $i->unit_price,
        ])->all();

        // Best-effort: align by index order — bills built from POs preserve order.
        $aligned = [];
        foreach ($po->items as $idx => $poi) {
            if (isset($billLines[$idx])) {
                $aligned[(string) $poi->item_id] = $billLines[$idx];
            }
        }
        return $this->matchForPo($po, array_values($aligned));
    }
}
