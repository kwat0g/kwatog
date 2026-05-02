<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\ReorderMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'category_id', 'item_type',
        'unit_of_measure', 'standard_cost', 'reorder_method',
        'reorder_point', 'safety_stock', 'minimum_order_quantity',
        'lead_time_days', 'is_critical', 'is_active',
    ];

    protected $casts = [
        'item_type'              => ItemType::class,
        'reorder_method'         => ReorderMethod::class,
        'standard_cost'          => 'decimal:4',
        'reorder_point'          => 'decimal:3',
        'safety_stock'           => 'decimal:3',
        'minimum_order_quantity' => 'decimal:3',
        'lead_time_days'         => 'integer',
        'is_critical'            => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function approvedSuppliers(): HasMany
    {
        return $this->hasMany(\App\Modules\Purchasing\Models\ApprovedSupplier::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function getOnHandAttribute(): string
    {
        return (string) $this->stockLevels()->sum('quantity');
    }

    public function getReservedAttribute(): string
    {
        return (string) $this->stockLevels()->sum('reserved_quantity');
    }

    public function getAvailableAttribute(): string
    {
        $onHand   = (float) $this->on_hand;
        $reserved = (float) $this->reserved;
        return number_format(max(0.0, $onHand - $reserved), 3, '.', '');
    }

    public function getStockStatusAttribute(): string
    {
        $available = (float) $this->available;
        $safety    = (float) $this->safety_stock;
        $reorder   = (float) $this->reorder_point;
        if ($available <= $safety) return 'critical';
        if ($available <= $reorder) return 'low';
        return 'ok';
    }

    public function canDelete(): bool
    {
        if ($this->stockLevels()->where('quantity', '>', 0)->exists()) return false;
        if ($this->movements()->exists()) return false;
        return true;
    }
}
