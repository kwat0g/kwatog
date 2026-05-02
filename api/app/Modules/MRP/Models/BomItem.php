<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'bom_id', 'item_id', 'quantity_per_unit', 'unit', 'waste_factor', 'sort_order',
    ];

    protected $casts = [
        'quantity_per_unit' => 'decimal:4',
        'waste_factor'      => 'decimal:2',
        'sort_order'        => 'integer',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * qty_per_unit * (1 + waste_factor/100). Returns string for precision.
     */
    public function getEffectiveQuantityAttribute(): string
    {
        $base  = (float) $this->quantity_per_unit;
        $waste = (float) $this->waste_factor;
        return number_format($base * (1 + $waste / 100), 4, '.', '');
    }
}
