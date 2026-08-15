<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $table = 'bill_of_materials';

    protected $fillable = [
        'product_id', 'version', 'is_active', 'material_cost', 'labor_cost',
        'machine_cost', 'overhead_cost', 'total_cost', 'cost_basis',
        'costed_at', 'cost_warnings',
    ];

    protected $casts = [
        'version'   => 'integer',
        'is_active' => 'boolean',
        'material_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'machine_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'costed_at' => 'datetime',
        'cost_warnings' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sort_order');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
