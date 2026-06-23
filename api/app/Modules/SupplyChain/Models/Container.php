<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\SupplyChain\Enums\ContainerSize;
use App\Modules\SupplyChain\Enums\ContainerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Container tracking for multi-container shipments (resin imports typically have 2-5 containers). */
class Container extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'shipment_id', 'container_number', 'seal_number',
        'size', 'type', 'gross_weight_kg', 'net_weight_kg',
        'volume_cbm', 'notes',
    ];

    protected $casts = [
        'size'             => ContainerSize::class,
        'type'             => ContainerType::class,
        'gross_weight_kg'  => 'decimal:2',
        'net_weight_kg'    => 'decimal:2',
        'volume_cbm'       => 'decimal:3',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
