<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OGAMI-004 — canonical Unit-of-Measure catalog (KG, BAG, PALLET, …).
 *
 * Item base UOM is still carried by `items.unit_of_measure` (a string matched
 * against {@see Uom::$code}); this model exists so conversion rows can FK a
 * known unit on each end and the SPA can offer a managed dropdown.
 */
class Uom extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'uoms';

    protected $fillable = ['code', 'name'];

    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(ItemUomConversion::class, 'from_uom_id');
    }

    public function conversionsTo(): HasMany
    {
        return $this->hasMany(ItemUomConversion::class, 'to_uom_id');
    }
}
