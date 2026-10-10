<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcomeMovement;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnRequestItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_id',
        'product_id',
        'item_id',
        'quantity',
        'returned_quantity',
        'receipt_recorded',
        'unit_price',
        'original_unit_price',
        'total',
        'reversal_already_applied',
        'reason',
        'condition',
        'disposition',
        'disposition_notes',
        'ncr_id',
        'stock_movement_quantity',
        'stock_movement_id',
        'source_sales_order_item_id',
        'source_invoice_item_id',
        'source_delivery_item_id',
        'delivery_attempt_outcome_movement_id',
        'source_po_item_id',
        'source_grn_item_id',
        'source_bill_item_id',
        'lot_number',
        'serial_number',
        'quarantine_location_id',
        'quarantine_movement_id',
        'quarantine_release_movement_id',
        'quarantine_status',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'returned_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'original_unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'stock_movement_quantity' => 'decimal:3',
        'receipt_recorded' => 'boolean',
        'reversal_already_applied' => 'boolean',
    ];

    /** Exact line ownership for new captures; historical product coverage remains usable. */
    public function qualityInspections(): Builder
    {
        return Inspection::query()
            ->where('entity_type', 'return_request')->where('entity_id', $this->return_request_id)
            ->where('stage', $this->returnRequest->type->value)->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->where('return_request_item_id', $this->id)
                ->when($this->product_id, fn ($legacy) => $legacy->orWhere(fn ($row) => $row->whereNull('return_request_item_id')->where('product_id', $this->product_id))));
    }

    /** Reuse only independently checked QC for the same already-rejected receipt item. */
    public function reusedIncomingInspection(): ?Inspection
    {
        if (! $this->source_grn_item_id || ! $this->item_id
            || $this->returnRequest?->type?->value !== 'supplier_return') {
            return null;
        }
        $line = $this->sourceGrnItem;
        if (! $line || (int) $line->item_id !== (int) $this->item_id) {
            return null;
        }

        $original = Inspection::query()->where('grn_item_id', $line->id)
            ->where('stage', 'incoming')->where('status', 'failed')->orderByDesc('id')->get()
            ->first(fn ($inspection) => $inspection->isMakerChecked());
        if ($this->reversal_already_applied) {
            return $original;
        }
        if ($this->stock_movement_id && bccomp((string) $this->stock_movement_quantity, (string) $this->quantity, 3) === 0) {
            $review = MaterialReviewRecord::query()
                ->where('release_movement_id', $this->stock_movement_id)->where('item_id', $this->item_id)
                ->where('disposition', 'return_to_supplier')->where('status', '!=', 'held')->with('inspection')->first();
            if ($review?->inspection?->grn_item_id === $line->id && $review->held_by !== $review->released_by) {
                return $review->inspection;
            }
        }

        return null;
    }

    /** Split lines share one receipt movement; allocated quantities must still conserve it exactly. */
    public function hasConservedReceiptAllocation(StockMovement $movement): bool
    {
        $rootId = (int) ($this->split_parent_item_id ?? $this->id);
        $allocations = ReturnReceiptItem::query()->where('stock_movement_id', $movement->id)->with('returnRequestItem')->get();
        $sum = '0.000';
        foreach ($allocations as $allocation) {
            $line = $allocation->returnRequestItem;
            if (! $line || (int) $line->return_request_id !== (int) $this->return_request_id
                || (int) $line->item_id !== (int) $this->item_id
                || (int) ($line->split_parent_item_id ?? $line->id) !== $rootId) {
                return false;
            }
            $sum = bcadd($sum, (string) $allocation->quantity, 3);
        }

        return $allocations->isNotEmpty() && bccomp($sum, (string) $movement->quantity, 3) === 0;
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function sourceGrnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class, 'source_grn_item_id');
    }

    public function sourceDeliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class, 'source_delivery_item_id');
    }

    public function deliveryAttemptOutcomeMovement(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttemptOutcomeMovement::class, 'delivery_attempt_outcome_movement_id');
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function quarantineLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'quarantine_location_id');
    }

    public function quarantineMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'quarantine_movement_id');
    }

    public function quarantineReleaseMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'quarantine_release_movement_id');
    }

    public function materialReviewRecord(): HasOne
    {
        return $this->hasOne(MaterialReviewRecord::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function sourceAllocations(): HasMany
    {
        return $this->hasMany(ReturnRequestSourceAllocation::class);
    }
}
