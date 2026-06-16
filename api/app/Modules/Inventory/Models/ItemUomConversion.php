<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-004 — per-item conversion factor.
 *
 * factor = how many base (`to_uom`) units there are per ONE `from_uom` unit.
 *   1 BAG = 25 KG  →  factor = 25.000000
 */
class ItemUomConversion extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = ['item_id', 'from_uom_id', 'to_uom_id', 'factor'];

    protected $casts = [
        'factor' => 'decimal:6',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'from_uom_id');
    }

    public function toUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'to_uom_id');
    }
}
