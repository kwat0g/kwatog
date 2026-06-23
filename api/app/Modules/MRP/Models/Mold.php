<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MoldStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mold extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'mold_code', 'name', 'product_id', 'cavity_count', 'cycle_time_seconds',
        'output_rate_per_hour', 'setup_time_minutes', 'current_shot_count',
        'max_shots_before_maintenance', 'lifetime_total_shots',
        'lifetime_max_shots', 'status', 'location', 'asset_id',
        // Lifecycle manager.
        'commissioned_at', 'decommissioned_at', 'last_maintenance_at',
        'maintenance_count', 'total_maintenance_cost', 'acquisition_cost',
        'estimated_replacement_cost', 'maintenance_frequency_shots',
        'drawing_number', 'storage_location',
    ];

    protected $casts = [
        'status'                       => MoldStatus::class,
        'cavity_count'                 => 'integer',
        'cycle_time_seconds'           => 'integer',
        'output_rate_per_hour'         => 'integer',
        'setup_time_minutes'           => 'integer',
        'current_shot_count'           => 'integer',
        'max_shots_before_maintenance' => 'integer',
        'lifetime_total_shots'         => 'integer',
        'lifetime_max_shots'           => 'integer',
        'asset_id'                     => 'integer',
        // Lifecycle manager.
        'commissioned_at'              => 'date',
        'decommissioned_at'            => 'date',
        'last_maintenance_at'          => 'date',
        'maintenance_count'            => 'integer',
        'total_maintenance_cost'       => 'decimal:2',
        'acquisition_cost'             => 'decimal:2',
        'estimated_replacement_cost'   => 'decimal:2',
        'maintenance_frequency_shots'  => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function compatibleMachines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class, 'mold_machine_compatibility');
    }

    public function history(): HasMany
    {
        return $this->hasMany(MoldHistory::class);
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('status', MoldStatus::Available->value);
    }

    public function getShotPercentageAttribute(): float
    {
        $max = (int) $this->max_shots_before_maintenance;
        if ($max <= 0) return 0.0;
        return round(((int) $this->current_shot_count / $max) * 100, 1);
    }

    public function getNearingLimitAttribute(): bool
    {
        return $this->shot_percentage >= 80.0;
    }

    /** Lifecycle cost per shot = (acquisition + total maintenance) / lifetime shots. */
    public function getCostPerShotAttribute(): float
    {
        $shots = (int) $this->lifetime_total_shots;
        if ($shots <= 0) return 0.0;
        $lifecycle = (float) $this->acquisition_cost + (float) $this->total_maintenance_cost;
        return round($lifecycle / $shots, 4);
    }

    /** Estimated shots remaining before the maintenance ceiling. */
    public function getShotsRemainingAttribute(): int
    {
        return max(0, (int) $this->max_shots_before_maintenance - (int) $this->current_shot_count);
    }
}
