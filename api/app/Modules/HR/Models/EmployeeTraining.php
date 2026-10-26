<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTraining extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

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

    public function scopeExpiredForActiveEmployees(Builder $query): Builder
    {
        return $query
            ->where('status', EmployeeTrainingStatus::Expired->value)
            ->currentCertification()
            ->whereHas('employee', fn (Builder $employee): Builder => $employee->where('status', EmployeeStatus::Active->value));
    }

    public function scopeUpcomingOrExpiringForActiveEmployees(Builder $query): Builder
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();

        return $query
            ->whereHas('employee', fn (Builder $employee): Builder => $employee->where('status', EmployeeStatus::Active->value))
            ->where(function (Builder $signals) use ($today): void {
                $signals->where(function (Builder $scheduled) use ($today): void {
                    $scheduled->where('status', EmployeeTrainingStatus::Scheduled->value)
                        ->whereBetween('scheduled_for', [$today->toDateString(), $today->copy()->addDays(14)->toDateString()]);
                })->orWhere(function (Builder $expiring) use ($today): void {
                    $expiring->where('status', EmployeeTrainingStatus::Completed->value)
                        ->whereBetween('expires_at', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
                        ->currentCertification();
                });
            });
    }

    public function scopeCurrentCertification(Builder $query): Builder
    {
        return $query->whereNotExists(function ($newer): void {
            $newer->selectRaw('1')
                ->from('employee_trainings as newer')
                ->whereColumn('newer.employee_id', 'employee_trainings.employee_id')
                ->whereColumn('newer.training_id', 'employee_trainings.training_id')
                ->whereIn('newer.status', [EmployeeTrainingStatus::Completed->value, EmployeeTrainingStatus::Expired->value])
                ->where(function ($completion): void {
                    $completion->whereColumn('newer.completed_at', '>', 'employee_trainings.completed_at')
                        ->orWhere(function ($tie): void {
                            $tie->whereColumn('newer.completed_at', 'employee_trainings.completed_at')
                                ->whereColumn('newer.id', '>', 'employee_trainings.id');
                        });
                });
        });
    }
}
