<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierItemListing extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $fillable = [
        'vendor_id', 'item_id',
        'supplier_item_code', 'supplier_item_name',
        'price', 'order_uom', 'base_qty_per_order_unit',
        'lead_time_days', 'valid_until',
        'rejection_reason', 'submitted_at',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_qty_per_order_unit' => 'decimal:4',
        'lead_time_days' => 'integer',
        'valid_until' => 'date',
        'status' => SupplierListingStatus::class,
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedSupplier(): HasOne
    {
        return $this->hasOne(ApprovedSupplier::class, 'supplier_listing_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', SupplierListingStatus::Pending->value);
    }
}
