<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\ApplicationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    use HasHashId;

    protected $fillable = [
        'application_number',
        'job_posting_id',
        'tracking_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'resume_path',
        'resume_original_name',
        'cover_letter',
        'applied_at',
    ];

    protected $casts = [
        'stage'      => ApplicationStage::class,
        'applied_at' => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function convertedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'converted_employee_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(ApplicationInterview::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ApplicationNote::class);
    }
}
