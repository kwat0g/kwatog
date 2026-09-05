<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovedSupplier extends Model
{
    use HasAuditLog, HasFactory, HasHashId, SoftDeletes;

    protected $fillable = [
        'item_id', 'vendor_id', 'is_preferred',
        'lead_time_days', 'last_price', 'last_price_at',
        'supplier_item_code', 'supplier_item_name',
        'order_uom', 'base_qty_per_order_unit',
        'price_valid_until', 'supplier_listing_id',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
        'lead_time_days' => 'integer',
        'last_price' => 'decimal:2',
        'last_price_at' => 'datetime',
        'base_qty_per_order_unit' => 'decimal:4',
        'price_valid_until' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
