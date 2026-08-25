<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable snapshot of a supplier shipment mutation. */
class SupplierShipmentUpdate extends Model
{
    use HasFactory, HasHashId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'supplier_shipment_id',
        'portal_user_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function supplierShipment(): BelongsTo
    {
        return $this->belongsTo(SupplierShipment::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class, 'portal_user_id')->withTrashed();
    }
}
