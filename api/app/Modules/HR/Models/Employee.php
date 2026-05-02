<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\PayType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'employee_no',
        'first_name', 'middle_name', 'last_name', 'suffix',
        'birth_date', 'gender', 'civil_status', 'nationality', 'photo_path',
        'street_address', 'barangay', 'city', 'province', 'zip_code',
        'mobile_number', 'email',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'sss_no', 'philhealth_no', 'pagibig_no', 'tin',
        'department_id', 'position_id',
        'employment_type', 'pay_type',
        'date_hired', 'date_regularized',
        'basic_monthly_salary', 'daily_rate',
        'bank_name', 'bank_account_no',
        'status',
    ];

    protected $casts = [
        'birth_date'           => 'date',
        'date_hired'           => 'date',
        'date_regularized'     => 'date',
        'basic_monthly_salary' => 'decimal:2',
        'daily_rate'           => 'decimal:2',
        // Encrypted at rest
        'sss_no'               => 'encrypted',
        'philhealth_no'        => 'encrypted',
        'pagibig_no'           => 'encrypted',
        'tin'                  => 'encrypted',
        'bank_account_no'      => 'encrypted',
        // Enums
        'status'               => EmployeeStatus::class,
        'employment_type'      => EmploymentType::class,
        'pay_type'             => PayType::class,
        'gender'               => Gender::class,
        'civil_status'         => CivilStatus::class,
    ];

    // ─── Accessors ─────────────────────────────────
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : null,
            $this->last_name,
            $this->suffix,
        ]);
        return implode(' ', $parts);
    }

    // ─── Relationships ─────────────────────────────
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(\App\Modules\Auth\Models\User::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->orderByDesc('uploaded_at');
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class)->orderByDesc('effective_date');
    }

    public function property(): HasMany
    {
        return $this->hasMany(EmployeeProperty::class)->orderByDesc('date_issued');
    }

    // ─── Scopes ────────────────────────────────────
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', EmployeeStatus::Active->value);
    }

    public function scopeInDepartment(Builder $q, int $departmentId): Builder
    {
        return $q->where('department_id', $departmentId);
    }
}
