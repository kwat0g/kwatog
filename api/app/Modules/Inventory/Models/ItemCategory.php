<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = ['name', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    /**
     * Lazy-load-safe: walks the chain only as long as `parent` is already
     * eager-loaded; otherwise stops. Callers wanting the full path must
     * `->with('parent.parent.parent')` or load explicitly.
     */
    public function getPathAttribute(): string
    {
        $parts = [$this->name];
        $current = $this;
        while ($current->relationLoaded('parent') && $current->parent) {
            $current = $current->parent;
            array_unshift($parts, $current->name);
        }
        return implode(' > ', $parts);
    }
}
