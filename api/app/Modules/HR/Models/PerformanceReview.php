<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformanceReview extends Model
{
    use SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'review_cycle_id', 'employee_id', 'reviewer_id', 'template_id',
        'ratings', 'strengths', 'improvements', 'goals',
        'overall_score', 'overall_rating',
    ];

    protected $casts = [
        'status'          => ReviewStatus::class,
        'ratings'         => 'array',
        'overall_score'   => 'decimal:2',
        'submitted_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class);
    }
}
