<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTrainingService
{
    public function assign(
        Employee $employee,
        Training $training,
        ?Carbon $scheduledFor = null,
        ?User $by = null,
    ): EmployeeTraining {
        return DB::transaction(function () use ($employee, $training, $scheduledFor, $by) {
            $this->assertNoOpenAssignment($employee, $training, $scheduledFor);

            $rec = EmployeeTraining::create([
                'employee_id'   => $employee->id,
                'training_id'   => $training->id,
                'scheduled_for' => $scheduledFor?->toDateString(),
                'created_by'    => $by?->id,
            ]);

            $rec->forceFill(['status' => EmployeeTrainingStatus::Scheduled->value])->save();
            return $rec->fresh(['employee', 'training']);
        });
    }

    public function recordCompletion(
        EmployeeTraining $record,
        Carbon $completedAt,
        ?string $certificatePath = null,
        ?User $by = null,
    ): EmployeeTraining {
        return DB::transaction(function () use ($record, $completedAt, $certificatePath) {
            $training = $record->training()->first();

            $expiresAt = $training?->validity_months
                ? $completedAt->copy()->addMonths((int) $training->validity_months)
                : null;

            $record->forceFill([
                'completed_at'     => $completedAt->toDateString(),
                'expires_at'       => $expiresAt?->toDateString(),
                'status'           => EmployeeTrainingStatus::Completed->value,
                'certificate_path' => $certificatePath ?? $record->certificate_path,
                'last_alert_level' => null,
                'last_alert_at'    => null,
            ])->save();

            return $record->fresh(['employee', 'training']);
        });
    }

    public function cancel(EmployeeTraining $record, ?string $reason = null, ?User $by = null): EmployeeTraining
    {
        return DB::transaction(function () use ($record, $reason) {
            $note = $reason ? trim(($record->notes ? $record->notes . "\n" : '') . "[cancelled] {$reason}") : $record->notes;
            $record->forceFill([
                'status' => EmployeeTrainingStatus::Cancelled->value,
                'notes'  => $note,
            ])->save();

            return $record->fresh(['employee', 'training']);
        });
    }

    private function assertNoOpenAssignment(Employee $employee, Training $training, ?Carbon $scheduledFor): void
    {
        $exists = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('training_id', $training->id)
            ->whereIn('status', [
                EmployeeTrainingStatus::Scheduled->value,
                EmployeeTrainingStatus::Completed->value,
            ])
            ->when(
                $scheduledFor !== null,
                fn($q) => $q->whereDate('scheduled_for', $scheduledFor->toDateString()),
                fn($q) => $q->whereNull('scheduled_for'),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'training_id' => ['Employee already has an open or completed assignment for this training on that date.'],
            ]);
        }
    }
}
