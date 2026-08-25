<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTraining extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    public const UNSCHEDULED_ASSIGNMENT_KEY = '__unscheduled__';

    /**
     * status / last_alert_level / last_alert_at are mutated only via
     * forceFill() inside services — never mass-assigned from controllers.
     */
    protected $fillable = [
        'employee_id', 'training_id', 'scheduled_for', 'completed_at',
        'expires_at', 'certificate_path', 'certificate_original_name',
        'certificate_mime_type', 'certificate_size', 'certificate_uploaded_by',
        'certificate_uploaded_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_for'    => 'date',
        'completed_at'     => 'date',
        'expires_at'       => 'date',
        'last_alert_at'    => 'datetime',
        'certificate_uploaded_at' => 'datetime',
        'status'           => EmployeeTrainingStatus::class,
        'last_alert_level' => TrainingAlertLevel::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $scheduledFor = $record->getAttribute('scheduled_for');
            $record->setAttribute(
                'assignment_key',
                $scheduledFor === null
                    ? self::UNSCHEDULED_ASSIGNMENT_KEY
                    : Carbon::parse((string) $scheduledFor)->toDateString(),
            );
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function certificateUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certificate_uploaded_by');
    }
}
