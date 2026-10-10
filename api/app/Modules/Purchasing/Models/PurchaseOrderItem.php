<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Support\Money;
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
        $diff = bcsub($this->baseOrderedQuantity(), (string) $this->quantity_accepted, 3);

        return $this->formatQuantity(bccomp($diff, '0', 3) > 0 ? $diff : '0');
    }

    public function getPhysicalQuantityRemainingAttribute(): string
    {
        $diff = bcsub($this->baseOrderedQuantity(), (string) $this->quantity_received, 3);

        return $this->formatQuantity(bccomp($diff, '0', 3) > 0 ? $diff : '0');
    }

    public function baseOrderedQuantity(): string
    {
        $item = $this->relationLoaded('item') ? $this->item : $this->item()->withTrashed()->first();

        return $item ? $item->convertToBase((string) $this->quantity, $this->unit) : (string) $this->quantity;
    }

    /** Convert base-unit receipt totals to the agreed purchase unit for supplier documents. */
    public function purchaseQuantity(string $baseQuantity, int $scale = 3): string
    {
        $item = $this->relationLoaded('item') ? $this->item : $this->item()->withTrashed()->first();
        $factor = $item ? $item->convertToBase('1', $this->unit) : '1';
        $quantity = bcdiv($baseQuantity, $factor, $scale);

        return $scale === 3 ? $this->formatQuantity($quantity) : $quantity;
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
     * Header charges are allocated to whole cents before deriving the unit cost.
     * Billing uses deliveredLineTotal() to avoid multiplying a rounded unit cost.
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

        $delivered = bcdiv($this->deliveredLineTotal(), $qty, 8);

        // Round to 4 decimal places (GrnItem.unit_cost scale) using half-up.
        // bcmath defaults to truncation; we add 0.00005 and truncate to achieve half-up.
        $sign = bccomp($delivered, '0', 8) < 0 ? '-' : '';
        $abs = $sign === '-' ? ltrim($delivered, '-') : $delivered;
        $rounded = bcadd($abs, '0.00005', 4);

        return $sign === '-' ? '-'.$rounded : $rounded;
    }

    /** The agreed net amount, allocating every header charge cent exactly once. */
    public function deliveredLineTotal(): string
    {
        $lineValue = Money::add(
            bcmul((string) $this->quantity, (string) $this->unit_price, 8),
            (string) ($this->rfq_line_freight_amount ?? '0'),
            (string) ($this->rfq_line_other_charges ?? '0'),
        );
        $po = $this->purchaseOrder;
        $headerCharges = Money::add((string) ($po?->rfq_freight_amount ?? '0'), (string) ($po?->rfq_other_charges ?? '0'));
        if (Money::isZero($headerCharges)) {
            return $lineValue;
        }

        $weights = [];
        foreach ($po->items->sortBy('id') as $item) {
            $weights[$item->id] = Money::add(
                bcmul((string) $item->quantity, (string) $item->unit_price, 8),
                (string) ($item->rfq_line_freight_amount ?? '0'),
                (string) ($item->rfq_line_other_charges ?? '0'),
            );
        }

        return Money::add($lineValue, Money::allocate($headerCharges, $weights)[$this->id] ?? '0');
    }
}
