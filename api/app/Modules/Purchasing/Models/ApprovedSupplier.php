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

class ApprovedSupplier extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'item_id', 'vendor_id', 'is_preferred',
        'lead_time_days', 'last_price', 'last_price_at',
    ];

    protected $casts = [
        'is_preferred'   => 'boolean',
        'lead_time_days' => 'integer',
        'last_price'     => 'decimal:2',
        'last_price_at'  => 'datetime',
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
