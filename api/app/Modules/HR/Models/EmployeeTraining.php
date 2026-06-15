<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTraining extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    /**
     * status / last_alert_level / last_alert_at are mutated only via
     * forceFill() inside services — never mass-assigned from controllers.
     */
    protected $fillable = [
        'employee_id', 'training_id', 'scheduled_for', 'completed_at',
        'expires_at', 'certificate_path', 'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_for'    => 'date',
        'completed_at'     => 'date',
        'expires_at'       => 'date',
        'last_alert_at'    => 'datetime',
        'status'           => EmployeeTrainingStatus::class,
        'last_alert_level' => TrainingAlertLevel::class,
    ];

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
}
