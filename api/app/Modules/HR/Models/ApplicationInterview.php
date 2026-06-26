<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\InterviewOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationInterview extends Model
{
    use HasHashId;

    protected $fillable = [
        'job_application_id',
        'scheduled_at',
        'location',
        'interviewer_name',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'outcome'      => InterviewOutcome::class,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'created_by');
    }
}
