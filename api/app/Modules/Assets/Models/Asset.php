<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sprint 8 — Task 70. */
class Asset extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $table = 'assets';

    protected $fillable = [
        'asset_code',
        'name',
        'description',
        'category',
        'department_id',
        'acquisition_date',
        'acquisition_cost',
        'useful_life_years',
        'depreciation_method',
        'salvage_value',
        'accumulated_depreciation',
        'status',
        'disposed_date',
        'disposal_amount',
        'location',
        'insurance_policy_no',
        'insurance_provider',
        'insurance_expiry',
        'insured_value',
    ];

    protected $casts = [
        'category'                 => AssetCategory::class,
        'status'                   => AssetStatus::class,
        'depreciation_method'      => \App\Modules\Assets\Enums\DepreciationMethod::class,
        'acquisition_date'         => 'date',
        'disposed_date'            => 'date',
        'acquisition_cost'         => 'decimal:2',
        'salvage_value'            => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'disposal_amount'          => 'decimal:2',
        'useful_life_years'        => 'integer',
        'insurance_expiry'         => 'date',
        'insured_value'            => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderByDesc('period_year')->orderByDesc('period_month');
    }

    public function getMonthlyDepreciationAttribute(): string
    {
        $life = max(1, (int) $this->useful_life_years);
        $cost = (float) $this->acquisition_cost;
        $salvage = (float) $this->salvage_value;
        $depreciable = max(0.0, $cost - $salvage);

        $method = $this->depreciation_method instanceof \App\Modules\Assets\Enums\DepreciationMethod
            ? $this->depreciation_method
            : \App\Modules\Assets\Enums\DepreciationMethod::StraightLine;

        if ($method === \App\Modules\Assets\Enums\DepreciationMethod::DecliningBalance) {
            // 200% declining balance: annual rate = 2/life applied to the current
            // book value (cost - accumulated), floored at salvage. Monthly = /12.
            $bookValue = max(0.0, $cost - (float) $this->accumulated_depreciation);
            $annualRate = 2.0 / $life;
            $annual = max(0.0, ($bookValue - $salvage) > 0 ? $bookValue * $annualRate : 0.0);
            // Never depreciate below salvage in a single year.
            $annual = min($annual, max(0.0, $bookValue - $salvage));
            return number_format($annual / 12, 2, '.', '');
        }

        // Straight line (default).
        return number_format($depreciable / ($life * 12), 2, '.', '');
    }

    public function getBookValueAttribute(): string
    {
        $cost = (float) $this->acquisition_cost;
        $accum = (float) $this->accumulated_depreciation;
        return number_format(max(0.0, $cost - $accum), 2, '.', '');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', AssetStatus::Active->value);
    }
}
