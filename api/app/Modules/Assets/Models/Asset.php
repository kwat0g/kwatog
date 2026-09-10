<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Common\Support\Money;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Auth\Models\User;
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
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog, HasApprovalWorkflow;

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
        'disposal_reason',
        'disposal_request_amount',
        'disposal_request_date',
        'disposal_request_reason',
        'disposal_requested_by',
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
        'disposal_request_amount'  => 'decimal:2',
        'disposal_request_date'    => 'date',
        'useful_life_years'        => 'integer',
        'insurance_expiry'         => 'date',
        'insured_value'            => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function disposalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposal_requested_by');
    }

    /**
     * ApprovalService hook — the disposal requester, so the self-approval
     * guard can refuse them acting on their own disposal request.
     */
    public function approvalSubmitterId(): ?int
    {
        return $this->disposal_requested_by !== null ? (int) $this->disposal_requested_by : null;
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderByDesc('period_year')->orderByDesc('period_month');
    }

    public function getMonthlyDepreciationAttribute(): string
    {
        $life = max(1, (int) $this->useful_life_years);
        $cost = Money::round2((string) ($this->acquisition_cost ?? Money::zero()));
        $salvage = Money::round2((string) ($this->salvage_value ?? Money::zero()));
        $depreciable = Money::clampMin(Money::sub($cost, $salvage), Money::zero());

        $method = $this->depreciation_method instanceof DepreciationMethod
            ? $this->depreciation_method
            : DepreciationMethod::StraightLine;

        if ($method === DepreciationMethod::DecliningBalance) {
            // 200% declining balance: annual rate = 2/life applied to the current
            // book value (cost - accumulated), floored at salvage. Monthly = /12.
            $bookValue = $this->book_value;
            $base = Money::clampMin(Money::sub($bookValue, $salvage), Money::zero());
            $annualRate = Money::div('2.00', (string) $life, Money::INNER);
            $annual = Money::mul($base, $annualRate);
            // Never depreciate below salvage in a single year.
            if (Money::gt($annual, $base)) {
                $annual = $base;
            }
            return Money::round2(Money::div($annual, '12.00', Money::INNER));
        }

        // Straight line (default).
        return Money::round2(Money::div($depreciable, (string) ($life * 12), Money::INNER));
    }

    public function getBookValueAttribute(): string
    {
        $cost = Money::round2((string) ($this->acquisition_cost ?? Money::zero()));
        $accumulated = Money::round2((string) ($this->accumulated_depreciation ?? Money::zero()));

        return Money::clampMin(Money::sub($cost, $accumulated), Money::zero());
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', AssetStatus::Active->value);
    }
}
