<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = ['name', 'code', 'address', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function zones(): HasMany
    {
        return $this->hasMany(WarehouseZone::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
