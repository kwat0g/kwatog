<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovedSupplier extends Model
{
    use HasAuditLog, HasFactory, HasHashId, SoftDeletes;

    /**
     * A vendor qualified through supplier-listing review or explicit ASL entry.
     */
    public const QUALIFICATION_APPROVED = 'approved';

    /**
     * A vendor↔item link observed from a purchase (e.g. PO approval) that has
     * not been reviewed. Suggestive, never authoritative.
     */
    public const QUALIFICATION_PROVISIONAL = 'provisional';

    protected $fillable = [
        'item_id', 'vendor_id', 'is_preferred', 'qualification_status',
        'lead_time_days', 'last_price', 'last_price_at',
        'supplier_item_code', 'supplier_item_name',
        'order_uom', 'base_qty_per_order_unit',
        'price_valid_until', 'supplier_listing_id',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
        'qualification_status' => 'string',
        'lead_time_days' => 'integer',
        'last_price' => 'decimal:2',
        'last_price_at' => 'datetime',
        'base_qty_per_order_unit' => 'decimal:4',
        'price_valid_until' => 'date',
    ];

    /** Only qualified links count as "we can buy this here" for sourcing. */
    public function scopeQualified(Builder $q): Builder
    {
        return $q->where('qualification_status', self::QUALIFICATION_APPROVED);
    }

    public function isProvisional(): bool
    {
        return $this->qualification_status === self::QUALIFICATION_PROVISIONAL;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
