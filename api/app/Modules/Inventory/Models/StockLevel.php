<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use HasFactory, HasHashId;

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
        $on = (float) $this->quantity;
        $rs = (float) $this->reserved_quantity;
        return number_format(max(0.0, $on - $rs), 3, '.', '');
    }

    public function getTotalValueAttribute(): string
    {
        return number_format((float) $this->quantity * (float) $this->weighted_avg_cost, 2, '.', '');
    }
}
