<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'year',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'fiscal_year_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereYear('start_date', now()->year);
    }
}
