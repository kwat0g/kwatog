<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sprint 7 — Task 66. Fleet vehicle. */
class Vehicle extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'plate_number', 'name', 'vehicle_type', 'capacity_kg',
        'status', 'notes', 'asset_id',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
        'asset_id' => 'integer',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Assets\Models\Asset::class, 'asset_id');
    }
}
