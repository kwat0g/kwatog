<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily per-item-per-location stock position. Written by the W0-5 snapshot job;
 * never updated, only inserted (one row per item/location/date). Backfill is
 * impossible — levels before the first snapshot are unrecoverable — so
 * consumers must treat pre-history as "data not available", never zero.
 */
class StockLevelSnapshot extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'stock_level_snapshots';

    protected $fillable = [
        'item_id',
        'location_id',
        'quantity',
        'reserved_quantity',
        'weighted_avg_cost',
        'snapshot_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'weighted_avg_cost' => 'decimal:4',
        'snapshot_date' => 'date',
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
