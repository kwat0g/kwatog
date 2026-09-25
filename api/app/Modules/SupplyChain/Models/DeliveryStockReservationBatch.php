<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryStockReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryStockReservationBatch extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_id', 'request_key', 'payload_fingerprint', 'status', 'reserved_by', 'reserved_at',
    ];

    protected $casts = [
        'status' => DeliveryStockReservationStatus::class,
        'reserved_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(DeliveryStockReservation::class, 'reservation_batch_id');
    }

    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }
}
