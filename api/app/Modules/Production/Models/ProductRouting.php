<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A published process plan for a finished good.
 *
 * Versions are immutable and never deleted — they are the source definition
 * for work-order operations and for the conversion cost on a BOM, so
 * `HasAuditLog` records who published or reactivated each one. Exactly one
 * version per product may be active; the invariant is enforced by the
 * `product_routings_one_active_per_product_unique` partial index, not only by
 * the service.
 */
class ProductRouting extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'product_id',
        'version',
        'is_active',
        'total_cycle_time',
        'notes',
    ];

    protected $casts = [
        'total_cycle_time' => 'decimal:2',
        'is_active'        => 'boolean',
        'version'          => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'routing_id')->orderBy('sequence');
    }
}
