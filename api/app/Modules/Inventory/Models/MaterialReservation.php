<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialReservation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'item_id', 'work_order_id', 'location_id',
        'quantity', 'status', 'reserved_at', 'released_at',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'reserved_at' => 'datetime',
        'released_at' => 'datetime',
        'status'      => ReservationStatus::class,
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
