<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Current supplier-reported shipment state for one purchase order. */
class SupplierShipment extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'purchase_order_id',
        'portal_user_id',
        'shipped_date',
        'carrier',
        'tracking_number',
        'estimated_arrival',
        'notes',
    ];

    protected $casts = [
        'shipped_date' => 'date',
        'estimated_arrival' => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class, 'portal_user_id')->withTrashed();
    }

    public function updates(): HasMany
    {
        return $this->hasMany(SupplierShipmentUpdate::class);
    }
}
