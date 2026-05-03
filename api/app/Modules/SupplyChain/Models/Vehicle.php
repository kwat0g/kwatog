<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 7 — Task 66. Fleet vehicle. */
class Vehicle extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'plate_number', 'name', 'vehicle_type', 'capacity_kg',
        'status', 'notes',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
