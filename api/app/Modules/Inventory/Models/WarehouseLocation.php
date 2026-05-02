<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = ['zone_id', 'code', 'rack', 'bin', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'zone_id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Lazy-load-safe accessor: returns `{wh.code}-{zone.code}-{location.code}` only if the
     * required relations are already eager-loaded; otherwise falls back to the bare code.
     * Callers that need the full code MUST eager-load `zone.warehouse`.
     */
    public function getFullCodeAttribute(): string
    {
        if (! $this->relationLoaded('zone') || ! $this->zone) return $this->code;
        $zone = $this->zone;
        $wh = $zone->relationLoaded('warehouse') ? $zone->warehouse : null;
        return implode('-', array_filter([$wh?->code, $zone->code, $this->code]));
    }
}
