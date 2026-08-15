<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequestItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_id',
        'product_id',
        'item_id',
        'quantity',
        'returned_quantity',
        'unit_price',
        'original_unit_price',
        'total',
        'reason',
        'condition',
        'disposition',
        'disposition_notes',
        'ncr_id',
        'stock_movement_quantity',
        'source_sales_order_item_id',
        'source_invoice_item_id',
        'source_delivery_item_id',
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
        'quantity'               => 'decimal:3',
        'returned_quantity'      => 'decimal:3',
        'unit_price'             => 'decimal:2',
        'original_unit_price'    => 'decimal:2',
        'total'                  => 'decimal:2',
        'stock_movement_quantity' => 'decimal:3',
    ];

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
        return $this->belongsTo(\App\Modules\SupplyChain\Models\DeliveryItem::class, 'source_delivery_item_id');
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function quarantineLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\WarehouseLocation::class, 'quarantine_location_id');
    }

    public function quarantineMovement(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\StockMovement::class, 'quarantine_movement_id');
    }

    public function quarantineReleaseMovement(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\StockMovement::class, 'quarantine_release_movement_id');
    }
}
