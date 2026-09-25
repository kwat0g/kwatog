<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnCaseLine extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'description',
        'unit',
        'expected_quantity',
        'received_quantity',
        'missing_quantity',
        'defective_quantity',
        'lot_number',
        'serial_number',
        'reason',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'missing_quantity' => 'decimal:3',
        'defective_quantity' => 'decimal:3',
        'verified_missing_quantity' => 'decimal:3',
        'verified_defective_quantity' => 'decimal:3',
        'source_unit_price' => 'decimal:4',
    ];

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ReturnCaseReceiptAllocation::class);
    }

    public function sourceDeliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class, 'source_delivery_item_id');
    }

    public function sourcePoItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'source_po_item_id');
    }

    public function sourceGrnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class, 'source_grn_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
