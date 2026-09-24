<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use App\Common\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use HasFactory, HasHashId;

    protected static function newFactory(): \Database\Factories\StockLevelFactory
    {
        return \Database\Factories\StockLevelFactory::new();
    }

    public $timestamps = false;

    protected $fillable = [
        'item_id', 'location_id', 'quantity', 'reserved_quantity',
        'weighted_avg_cost', 'last_counted_at', 'lock_version',
    ];

    protected $casts = [
        'quantity'           => 'decimal:3',
        'reserved_quantity'  => 'decimal:3',
        'weighted_avg_cost'  => 'decimal:4',
        'last_counted_at'    => 'datetime',
        'lock_version'       => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function getAvailableAttribute(): string
    {
        $available = bcsub((string) $this->quantity, (string) $this->reserved_quantity, 3);

        return bccomp($available, '0', 3) < 0 ? '0.000' : $available;
    }

    public function getTotalValueAttribute(): string
    {
        return Money::round2(bcmul((string) $this->quantity, (string) $this->weighted_avg_cost, 7));
    }
}
