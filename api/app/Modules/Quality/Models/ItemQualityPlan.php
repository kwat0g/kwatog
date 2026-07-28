<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemQualityPlan extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'item_id', 'vendor_id', 'version', 'stage', 'sampling_method',
        'fixed_sample_size', 'aql_level', 'parameters', 'effective_from',
        'effective_to', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'fixed_sample_size' => 'integer',
        'parameters' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEffective(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
