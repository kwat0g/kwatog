<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'purchase_order_id', 'item_id', 'purchase_request_item_id',
        'rfq_award_id', 'supplier_quote_version_id',
        'rfq_line_vat_amount', 'rfq_line_freight_amount', 'rfq_line_other_charges',
        'description', 'quantity', 'unit', 'unit_price', 'total',
        'quantity_received', 'quantity_accepted',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'rfq_line_vat_amount' => 'decimal:2',
        'rfq_line_freight_amount' => 'decimal:2',
        'rfq_line_other_charges' => 'decimal:2',
        'quantity_accepted' => 'decimal:3',
    ];

    public function getQuantityAttribute(mixed $value): string
    {
        return $this->formatQuantity($value);
    }

    public function getQuantityReceivedAttribute(mixed $value): string
    {
        return $this->formatQuantity($value);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function rfqAward(): BelongsTo
    {
        return $this->belongsTo(RfqAward::class, 'rfq_award_id');
    }

    public function supplierQuoteVersion(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class, 'supplier_quote_version_id');
    }

    public function getQuantityRemainingAttribute(): string
    {
        // Operationally remaining means accepted/usable quantity; physical
        // receipts pending QC must not close or satisfy a PO line.
        $diff = bcsub((string) $this->quantity, (string) $this->quantity_accepted, 3);

        return $this->formatQuantity(bccomp($diff, '0', 3) > 0 ? $diff : '0');
    }

    public function getPhysicalQuantityRemainingAttribute(): string
    {
        $diff = bcsub((string) $this->quantity, (string) $this->quantity_received, 3);

        return $this->formatQuantity(bccomp($diff, '0', 3) > 0 ? $diff : '0');
    }

    private function formatQuantity(mixed $value): string
    {
        $quantity = bcadd((string) $value, '0', 3);

        return str_ends_with($quantity, '0') ? bcadd($quantity, '0', 2) : $quantity;
    }

    /**
     * Delivered unit cost: distributes line and header charges across quantity.
     *
     * When an RFQ PO carries freight/charges, they are capitalized into inventory
     * cost (landed cost). This method prorates them: line charges apply directly,
     * header charges are split by each line's proportional value.
     *
     * Formula (all bcmath, scale 8):
     *   lineValue = quantity × unit_price + rfq_line_freight + rfq_line_other
     *   totalValue = SUM(lineValue) across the PO
     *   headerShare = (po.rfq_freight + po.rfq_other) × (lineValue / totalValue)
     *   deliveredUnitCost = (lineValue + headerShare) / quantity
     *
     * Returns string rounded to 4 dp (matching GrnItem.unit_cost cast) using half-up.
     * When all charges are null/zero, returns exactly unit_price (non-RFQ POs byte-for-byte unchanged).
     * Guards division by zero.
     */
    public function deliveredUnitCost(): string
    {
        $lineFreight = (string) ($this->rfq_line_freight_amount ?? '0');
        $lineOther = (string) ($this->rfq_line_other_charges ?? '0');

        // If this line has no charges, check if the PO has header charges.
        // No charges at all → return unit_price.
        $poFreight = (string) ($this->purchaseOrder?->rfq_freight_amount ?? '0');
        $poOther = (string) ($this->purchaseOrder?->rfq_other_charges ?? '0');

        if (bccomp($lineFreight, '0', 8) === 0
            && bccomp($lineOther, '0', 8) === 0
            && bccomp($poFreight, '0', 8) === 0
            && bccomp($poOther, '0', 8) === 0) {
            return bcadd((string) $this->unit_price, '0', 4);
        }

        $qty = bcadd((string) $this->quantity, '0', 8);
        if (bccomp($qty, '0', 8) === 0) {
            // Shouldn't happen (PO validation), but guard division by zero.
            return bcadd((string) $this->unit_price, '0', 4);
        }

        // Compute this line's value before header allocation.
        $lineValue = bcmul($qty, (string) $this->unit_price, 8);
        $lineValue = bcadd($lineValue, $lineFreight, 8);
        $lineValue = bcadd($lineValue, $lineOther, 8);

        // Compute header share: PO charges × (this line value / total PO value).
        $headerShare = '0';
        $headerCharges = bcadd($poFreight, $poOther, 8);
        if (bccomp($headerCharges, '0', 8) !== 0) {
            // Compute total PO value (sum of all line values).
            $totalValue = '0';
            foreach ($this->purchaseOrder?->items ?? [] as $item) {
                $iQty = bcadd((string) $item->quantity, '0', 8);
                $iValue = bcmul($iQty, (string) $item->unit_price, 8);
                $iValue = bcadd($iValue, (string) ($item->rfq_line_freight_amount ?? '0'), 8);
                $iValue = bcadd($iValue, (string) ($item->rfq_line_other_charges ?? '0'), 8);
                $totalValue = bcadd($totalValue, $iValue, 8);
            }

            if (bccomp($totalValue, '0', 8) !== 0) {
                $shareRatio = bcdiv($lineValue, $totalValue, 8);
                $headerShare = bcmul($headerCharges, $shareRatio, 8);
            }
        }

        // Delivered unit cost = (line value + header share) / quantity.
        $totalLineValue = bcadd($lineValue, $headerShare, 8);
        $delivered = bcdiv($totalLineValue, $qty, 8);

        // Round to 4 decimal places (GrnItem.unit_cost scale) using half-up.
        // bcmath defaults to truncation; we add 0.00005 and truncate to achieve half-up.
        $sign = bccomp($delivered, '0', 8) < 0 ? '-' : '';
        $abs = $sign === '-' ? ltrim($delivered, '-') : $delivered;
        $rounded = bcadd($abs, '0.00005', 4);
        return $sign === '-' ? '-' . $rounded : $rounded;
    }
}
