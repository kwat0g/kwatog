<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'period_start',
        'period_end',
        'payroll_date',
        'is_first_half',
        'is_thirteenth_month',
        'status',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'period_start'        => 'date',
        'period_end'          => 'date',
        'payroll_date'        => 'date',
        'is_first_half'       => 'boolean',
        'is_thirteenth_month' => 'boolean',
        'status'              => PayrollPeriodStatus::class,
    ];

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function bankFileRecords(): HasMany
    {
        return $this->hasMany(BankFileRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNotFinalized(Builder $q): Builder
    {
        return $q->where('status', '!=', PayrollPeriodStatus::Finalized->value);
    }

    public function scopeForYear(Builder $q, int $year): Builder
    {
        return $q->whereYear('period_start', $year);
    }

    public function isLocked(): bool
    {
        return $this->status === PayrollPeriodStatus::Finalized;
    }

    public function label(): string
    {
        $start = $this->period_start?->format('M j');
        $end   = $this->period_end?->format('M j, Y');
        $half  = $this->is_thirteenth_month ? '13th Month' : ($this->is_first_half ? '1st half' : '2nd half');
        return "{$start}–{$end} · {$half}";
    }
}
