<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected static function newFactory(): \Database\Factories\BudgetFactory
    {
        return \Database\Factories\BudgetFactory::new();
    }

    protected $fillable = [
        'fiscal_year_id',
        'department_id',
        'budget_type',
        'name',
        'total_allocated',
        'total_spent',
        'total_committed',
        'status',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approved_by');
    }

    public function getAvailableAttribute(): float
    {
        return (float) ($this->total_allocated - $this->total_spent - $this->total_committed);
    }

    public function getUtilizationPercentAttribute(): float
    {
        if ($this->total_allocated <= 0) {
            return 0;
        }
        return round(($this->total_spent + $this->total_committed) / $this->total_allocated * 100, 1);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['approved', 'active']);
    }

    public function scopeByDepartment(Builder $q, int $departmentId): Builder
    {
        return $q->where('department_id', $departmentId);
    }

    public function scopeByFiscalYear(Builder $q, int $fiscalYearId): Builder
    {
        return $q->where('fiscal_year_id', $fiscalYearId);
    }
}
