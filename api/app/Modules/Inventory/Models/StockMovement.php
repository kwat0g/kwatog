<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'item_id', 'from_location_id', 'to_location_id',
        'movement_type', 'quantity', 'unit_cost', 'total_cost',
        'reference_type', 'reference_id', 'remarks', 'created_by', 'created_at',
    ];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'unit_cost'     => 'decimal:4',
        'total_cost'    => 'decimal:2',
        'movement_type' => StockMovementType::class,
        'reference_id'  => 'integer',
        'created_at'    => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
