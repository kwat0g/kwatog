<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\ThreeWayMatchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Variance check gating AP bill approval.
 *
 * Three-way (PO ↔ Bill ↔ GRN). PO-vs-Bill tolerances (qty + price) are
 * settings-driven (`purchasing.three_way_tolerance_qty_pct`,
 * `purchasing.three_way_tolerance_price_pct`). A partial receipt is valid:
 * billing less than the ordered quantity does not create a variance, while
 * billing above the PO or accepted GRN is still gated by the tolerance. The
 * received unit cost is also compared with the bill price when available.
 * Override is still available via BillService::create($data + allow_override=true)
 * and the draft-post review path, with the decision retained in the snapshot.
 *
 * H-7 + H-6 (2026-06): matchForBill aligns bill lines to PO lines by item_id
 * FK (not by index). Legacy bills without item_id on any line fall back to
 * the old index-based alignment with a logged warning.
 */
class ThreeWayMatchService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function matchForPo(PurchaseOrder $po, array $billLines, ?int $grnId = null, ?int $excludeBillId = null): ThreeWayMatchResult
    {
        // Load items with the parent PO relationship so deliveredUnitCost() can compute header allocation.
        $po->loadMissing('items.item');
        foreach ($po->items as $item) {
            $item->setRelation('purchaseOrder', $po);
        }

        $qtyTol = $this->nonNegativeTolerance('purchasing.three_way_tolerance_qty_pct');
        $priceTol = $this->nonNegativeTolerance('purchasing.three_way_tolerance_price_pct');

        // Aggregate accepted GRN qty per po_item.
        // When $grnId is given, restrict to that specific GRN only. A receipt's bill
        // may only claim that receipt's unbilled accepted qty; PO-wide aggregate
        // allowed a second bill to re-claim goods already billed via PPV.
        $grnQuery = GrnItem::query()
            ->whereIn('purchase_order_item_id', $po->items->pluck('id'));
        if ($grnId !== null) {
            $grnQuery->where('goods_receipt_note_id', $grnId);
        }
        $grnAccepted = $grnQuery
            ->select('purchase_order_item_id',
                DB::raw('SUM(quantity_accepted) as qty_accepted'),
                DB::raw('AVG(unit_cost) as avg_cost')
            )
            ->groupBy('purchase_order_item_id')
            ->get()
            ->keyBy('purchase_order_item_id');

        // Qty already billed on OTHER committed bills (drafts are re-matched when
        // posted), in the same scope as the accepted qty above. Bill lines may
        // use different units, so normalize each row before summing by item.
        $alreadyBilledQuery = BillItem::query()
            ->join('bills', 'bill_items.bill_id', '=', 'bills.id')
            ->where('bills.purchase_order_id', $po->id)
            ->whereIn('bill_items.item_id', $po->items->pluck('item_id'))
            ->whereNotIn('bills.status', ['draft', 'cancelled'])
            ->select('bill_items.item_id', 'bill_items.quantity', 'bill_items.unit');
        if ($excludeBillId !== null) {
            $alreadyBilledQuery->where('bills.id', '!=', $excludeBillId);
        }
        if ($grnId !== null) {
            $alreadyBilledQuery->where('bills.goods_receipt_note_id', $grnId);
        }
        $alreadyBilled = [];
        $poItemsByItemId = $po->items->keyBy('item_id');
        foreach ($alreadyBilledQuery->get() as $row) {
            $item = $poItemsByItemId[$row->item_id]?->item;
            if (! $item) {
                throw new BusinessRuleException('Cannot match a bill with a missing purchase-order item.');
            }
            $key = (string) $row->item_id;
            $alreadyBilled[$key] = bcadd(
                $alreadyBilled[$key] ?? '0',
                $item->convertToBase((string) $row->quantity, trim((string) $row->unit) ?: null),
                6,
            );
        }

        // Index bill lines by item_id (or by description fallback).
        $billByItem = [];
        $duplicateBillItems = [];
        foreach ($billLines as $bl) {
            $key = isset($bl['item_id']) && $bl['item_id'] ? (string) $bl['item_id'] : 'desc:'.($bl['description'] ?? '');
            if (array_key_exists($key, $billByItem)) {
                $duplicateBillItems[$key] = true;
            }
            $billByItem[$key] = $bl;
        }

        $lines = [];
        $overall = 'matched';
        $poItemKeys = [];
        foreach ($po->items as $poi) {
            $grn = $grnAccepted[$poi->id] ?? null;
            $billKey = (string) $poi->item_id;
            $poItemKeys[$billKey] = true;
            $bl = $billByItem[$billKey] ?? null;

            $item = $poi->item;
            if (! $item) {
                throw new BusinessRuleException('Cannot match a bill with a missing purchase-order item.');
            }
            $poFactor = $item->convertToBase('1', trim((string) $poi->unit) ?: null);
            $billFactor = $bl ? $item->convertToBase('1', trim((string) ($bl['unit'] ?? '')) ?: null) : '1';
            if (bccomp($poFactor, '0', 6) <= 0 || bccomp($billFactor, '0', 6) <= 0) {
                throw new BusinessRuleException('A purchase or bill unit must convert to a positive base quantity.');
            }
            $poQty = $item->convertToBase((string) $poi->quantity, trim((string) $poi->unit) ?: null);
            $billQty = $bl ? $item->convertToBase((string) $bl['quantity'], trim((string) ($bl['unit'] ?? '')) ?: null) : '0';
            $billUnitPrice = $bl ? (string) $bl['unit_price'] : '0';
            $poUnitPrice = $poi->deliveredUnitCost();
            // For RFQ POs, delivered cost includes distributed freight/charges.
            // GRN quantities and unit costs are already in the item's base UOM.
            $poPrice = bcdiv($poUnitPrice, $poFactor, 12);
            $billPrice = bcdiv($billUnitPrice, $billFactor, 12);
            $grnCost = $grn ? (string) $grn->avg_cost : $poPrice;
            $available = bcsub($grn ? (string) $grn->qty_accepted : '0', $alreadyBilled[(string) $poi->item_id] ?? '0', 6);
            $grnQty = bccomp($available, '0', 6) > 0 ? $available : '0';
            $billLinePresent = $bl !== null;

            // Under-billing is a normal partial-receipt state. Only an
            // overage against the ordered quantity is a quantity variance.
            // An omitted PO line is different from a present line billed at a
            // lower quantity: omission is an incomplete bill and must block.
            $qtyOver = bccomp($billQty, $poQty, 6) > 0 ? bcsub($billQty, $poQty, 6) : '0';
            $qtyVar = ! $billLinePresent ? 100.0 : $this->percent($qtyOver, $poQty);
            // Compare original prices by cross-multiplication: dividing by a
            // conversion factor can truncate a repeating base-unit price.
            $poPriceDifference = $this->decAbs(bcmul($billUnitPrice, $poFactor, 12), bcmul($poUnitPrice, $billFactor, 12));
            $poPriceReference = bcmul($poUnitPrice, $billFactor, 12);
            $grnPriceDifference = $this->decAbs($billUnitPrice, bcmul($grnCost, $billFactor, 12));
            $grnPriceReference = bcmul($grnCost, $billFactor, 12);
            $poPriceVar = $this->percent($poPriceDifference, $poPriceReference);
            $grnPriceVar = $grn && bccomp($grnQty, '0', 6) > 0
                ? $this->percent($grnPriceDifference, $grnPriceReference) : 0.0;
            $priceVar = max($poPriceVar, $grnPriceVar);

            // Decimal-exact pass/fail decisions: float rounding caused bills
            // priced exactly at the tolerance to block ~50% of the time
            // (e.g. PO 1.00 vs bill 1.05 at 5% computed 5.000000000000004 > 5).
            // Use BCMath on strings with cross-multiplication to avoid division.
            $qtyOk = $billLinePresent && (bccomp($poQty, '0', 6) <= 0
                ? bccomp($billQty, '0', 6) <= 0
                : bccomp(bcmul($qtyOver, '100', 12), bcmul($poQty, $this->dec($qtyTol), 12), 12) <= 0);

            $poPriceOk = bccomp($poPriceReference, '0', 12) > 0
                ? bccomp(bcmul($poPriceDifference, '100', 12), bcmul($this->dec($priceTol), $poPriceReference, 12), 12) <= 0
                : true;
            $grnPriceOk = ($grn && bccomp($grnQty, '0', 6) > 0 && bccomp($grnPriceReference, '0', 12) > 0)
                ? bccomp(bcmul($grnPriceDifference, '100', 12), bcmul($this->dec($priceTol), $grnPriceReference, 12), 12) <= 0
                : true;
            $priceOk = $poPriceOk && $grnPriceOk;

            // H-6 — Bill qty must not exceed accepted GRN qty beyond the qty
            // tolerance. If there is no GRN at all, any non-zero bill qty is
            // a hard block — you cannot pay for goods that were never received.
            if (bccomp($grnQty, '0', 6) > 0) {
                $grnOver = bccomp($billQty, $grnQty, 6) > 0 ? bcsub($billQty, $grnQty, 6) : '0';
                $grnOk = bccomp(bcmul($grnOver, '100', 12), bcmul($this->dec($qtyTol), $grnQty, 12), 12) <= 0;
            } else {
                $grnOk = bccomp($billQty, '0', 6) <= 0;
            }

            $severity = ($qtyOk && $priceOk && $grnOk) ? 'ok' : 'block';
            $lineStatus = match (true) {
                ! $qtyOk && ! $priceOk => 'both',
                ! $qtyOk => 'qty_variance',
                ! $priceOk => 'price_variance',
                ! $grnOk => 'grn_short',
                default => 'matched',
            };

            if ($severity === 'block') {
                $overall = 'blocked';
            } elseif ((bccomp($qtyOver, '0', 6) > 0 || bccomp($poPriceDifference, '0', 12) > 0
                || ($grn && bccomp($grnPriceDifference, '0', 12) > 0)) && $overall !== 'blocked') {
                $overall = 'has_variances';
            }

            $lines[] = [
                'item_id' => $poi->item_id,
                'item_code' => $poi->item?->code,
                'description' => $poi->description,
                'po_quantity' => bcadd($poQty, '0', 2),
                'po_unit_price' => Money::round2($poPrice),
                'po_total' => Money::round2(bcmul((string) $poi->quantity, $poUnitPrice, 12)),
                'grn_quantity_accepted' => bcadd($grnQty, '0', 3),
                'grn_unit_cost' => bcadd($grnCost, '0', 4),
                'grn_status' => $grnOk ? 'ok' : 'short',
                'bill_quantity' => bcadd($billQty, '0', 2),
                'bill_unit_price' => Money::round2($billPrice),
                'bill_total' => Money::round2(bcmul((string) ($bl['quantity'] ?? '0'), $billUnitPrice, 12)),
                'quantity_variance_pct' => round($qtyVar, 2),
                'price_variance_pct' => round($priceVar, 2),
                'po_price_variance_pct' => round($poPriceVar, 2),
                'grn_price_variance_pct' => round($grnPriceVar, 2),
                'status' => $lineStatus,
                'severity' => $severity,
            ];
        }

        // Never let a bill line disappear from the match merely because its
        // item is absent from the PO (or appears twice). The old implementation
        // matched only PO-owned rows, so an extra billed item could still post
        // while the snapshot looked clean.
        foreach ($billByItem as $key => $bl) {
            if (isset($poItemKeys[$key]) && ! isset($duplicateBillItems[$key])) {
                continue;
            }

            $billQty = (string) ($bl['quantity'] ?? '0');
            $billPrice = (string) ($bl['unit_price'] ?? '0');
            $status = isset($poItemKeys[$key]) ? 'duplicate_bill_line' : 'unmatched_bill_line';
            $overall = 'blocked';
            $lines[] = [
                'item_id' => $bl['item_id'] ?? null,
                'item_code' => null,
                'description' => (string) ($bl['description'] ?? 'Unmatched bill line'),
                'po_quantity' => '0.00',
                'po_unit_price' => '0.00',
                'po_total' => '0.00',
                'grn_quantity_accepted' => '0.000',
                'grn_unit_cost' => '0.0000',
                'grn_status' => 'short',
                'bill_quantity' => bcadd($billQty, '0', 2),
                'bill_unit_price' => Money::round2($billPrice),
                'bill_total' => Money::round2(bcmul($billQty, $billPrice, 12)),
                'quantity_variance_pct' => 100.0,
                'price_variance_pct' => 100.0,
                'po_price_variance_pct' => 100.0,
                'grn_price_variance_pct' => 100.0,
                'status' => $status,
                'severity' => 'block',
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

    /**
     * Convert a numeric value to a decimal string for bcmath operations.
     */
    private function dec(float|int|string $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        $formatted = number_format((float) $v, 6, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Return the absolute value of (a - b) as a decimal string for bcmath.
     */
    private function decAbs(float|int|string $a, float|int|string $b = 0): string
    {
        $decA = $this->dec($a);
        $decB = $this->dec($b);
        if (bccomp($decA, $decB, 12) < 0) {
            return bcsub($decB, $decA, 12);
        }

        return bcsub($decA, $decB, 12);
    }

    private function percent(string $difference, string $reference): float
    {
        return bccomp($reference, '0', 12) > 0
            ? round((float) bcdiv(bcmul($difference, '100', 12), $reference, 8), 2)
            : 0.0;
    }

    private function nonNegativeTolerance(string $key): float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value < 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (float) $value;
    }

    public function matchForBill(Bill $bill): ?ThreeWayMatchResult
    {
        if (! $bill->purchase_order_id) {
            return null;
        }
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
            }
            // Keep every row, including duplicate and null-FK rows. Passing
            // an associative map here used to overwrite duplicate bill lines
            // before matchForPo() could flag them.
            $billLinesByItem[] = [
                'item_id' => $bi->item_id,
                'description' => $bi->description,
                'quantity' => $bi->quantity,
                'unit' => $bi->unit,
                'unit_price' => $bi->unit_price,
            ];
        }

        if ($anyHasItemId) {
            return $this->matchForPo($po, array_values($billLinesByItem), $bill->goods_receipt_note_id, $bill->id);
        }

        // Legacy fallback: bill predates H-7 backfill (all item_ids NULL).
        // Best-effort index alignment, with a logged warning so we can spot
        // any rows the migration could not backfill.
        Log::warning('ThreeWayMatchService::matchForBill falling back to index alignment', [
            'bill_id' => $bill->id,
            'po_id' => $po->id,
        ]);

        $billLines = $bill->items->map(fn ($i) => [
            'item_id' => null,
            'description' => $i->description,
            'quantity' => $i->quantity,
            'unit' => $i->unit,
            'unit_price' => $i->unit_price,
        ])->all();

        $aligned = [];
        foreach ($po->items as $idx => $poi) {
            if (isset($billLines[$idx])) {
                $aligned[(string) $poi->item_id] = $billLines[$idx];
            }
        }

        return $this->matchForPo($po, array_values($aligned), $bill->goods_receipt_note_id, $bill->id);
    }
}
