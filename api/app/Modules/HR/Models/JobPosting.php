<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\JobPostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'posting_number',
        'position_id',
        'department_id',
        'title',
        'description',
        'requirements',
        'employment_type',
        'salary_range_min',
        'salary_range_max',
        'show_salary',
        'slots',
        'posted_at',
        'closes_at',
        'created_by',
    ];

    protected $casts = [
        'employment_type'  => EmploymentType::class,
        'status'           => JobPostingStatus::class,
        'salary_range_min' => 'decimal:2',
        'salary_range_max' => 'decimal:2',
        'show_salary'      => 'boolean',
        'slots'            => 'integer',
        'posted_at'        => 'datetime',
        'closes_at'        => 'datetime',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
