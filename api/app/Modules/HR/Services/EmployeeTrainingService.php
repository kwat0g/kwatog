<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use App\Modules\HR\Support\EmployeeTrainingStateMachine;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeTrainingService
{
    public function __construct(
        private readonly EmployeeTrainingStateMachine $stateMachine,
        private readonly TrainingEvidenceService $evidence,
    ) {}

    public function assign(
        Employee $employee,
        Training $training,
        ?Carbon $scheduledFor = null,
        ?User $by = null,
        ?string $notes = null,
    ): EmployeeTraining {
        EmployeeCompetenceScope::employee($employee, $by);

        try {
            return DB::transaction(function () use ($employee, $training, $scheduledFor, $by, $notes): EmployeeTraining {
                // Serializing on the employee row makes same-employee
                // assignments deterministic even before the unique index is
                // consulted. The index remains the final race-safe guard.
                $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
                EmployeeCompetenceScope::employee($lockedEmployee, $by);

                $this->assertNoOpenAssignment($lockedEmployee, $training, $scheduledFor);

                $record = EmployeeTraining::create([
                    'employee_id' => $lockedEmployee->id,
                    'training_id' => $training->id,
                    'scheduled_for' => $scheduledFor?->toDateString(),
                    'notes' => $notes,
                    'created_by' => $by?->id,
                ]);

                $record->forceFill(['status' => EmployeeTrainingStatus::Scheduled])->save();

                return $record->fresh(['employee', 'training']);
            });
        } catch (QueryException $e) {
            if (! $this->isAssignmentConflict($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'training_id' => ['Employee already has an assignment for this training on that date.'],
            ]);
        }
    }

    public function recordCompletion(
        EmployeeTraining $record,
        Carbon $completedAt,
        ?UploadedFile $certificate = null,
        ?User $by = null,
    ): EmployeeTraining {
        EmployeeCompetenceScope::training($record, $by);

        $newPath = null;
        $oldPath = null;

        try {
            $result = DB::transaction(function () use ($record, $completedAt, $certificate, $by, &$newPath, &$oldPath): EmployeeTraining {
                $locked = EmployeeTraining::query()
                    ->with(['employee', 'training'])
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());
                EmployeeCompetenceScope::training($locked, $by);
                $this->stateMachine->transition($locked, EmployeeTrainingStatus::Completed);

                $training = $locked->training;
                $expiresAt = $training?->validity_months
                    ? $completedAt->copy()->addMonths((int) $training->validity_months)
                    : null;

                $evidence = $certificate
                    ? $this->evidence->store($certificate, 'employee-training-certificates/'.$locked->id)
                    : null;
                $newPath = $evidence['path'] ?? null;
                $oldPath = $locked->certificate_path;

                $locked->forceFill([
                    'completed_at' => $completedAt->toDateString(),
                    'expires_at' => $expiresAt?->toDateString(),
                    'status' => EmployeeTrainingStatus::Completed,
                    'certificate_path' => $evidence['path'] ?? $locked->certificate_path,
                    'certificate_original_name' => $evidence['original_name'] ?? $locked->certificate_original_name,
                    'certificate_mime_type' => $evidence['mime_type'] ?? $locked->certificate_mime_type,
                    'certificate_size' => $evidence['size'] ?? $locked->certificate_size,
                    'certificate_uploaded_by' => $evidence ? $by?->id : $locked->certificate_uploaded_by,
                    'certificate_uploaded_at' => $evidence ? now() : $locked->certificate_uploaded_at,
                    'last_alert_level' => null,
                    'last_alert_at' => null,
                ])->save();

                if ($evidence !== null && $oldPath !== null && $oldPath !== $evidence['path']) {
                    DB::afterCommit(fn () => $this->evidence->delete($oldPath));
                }

                return $locked->fresh(['employee', 'training']);
            });

            $newPath = null;
            return $result;
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $this->evidence->delete($newPath);
            }
            throw $e;
        }
    }

    public function cancel(EmployeeTraining $record, ?string $reason = null, ?User $by = null): EmployeeTraining
    {
        EmployeeCompetenceScope::training($record, $by);

        return DB::transaction(function () use ($record, $reason, $by): EmployeeTraining {
            $locked = EmployeeTraining::query()
                ->with(['employee', 'training'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());
            EmployeeCompetenceScope::training($locked, $by);
            $this->stateMachine->transition($locked, EmployeeTrainingStatus::Cancelled);

            $note = $reason
                ? trim(($locked->notes ? $locked->notes."\n" : '')."[cancelled] {$reason}")
                : $locked->notes;
            $locked->forceFill([
                'status' => EmployeeTrainingStatus::Cancelled,
                'notes' => $note,
            ])->save();

            return $locked->fresh(['employee', 'training']);
        });
    }

    private function assertNoOpenAssignment(Employee $employee, Training $training, ?Carbon $scheduledFor): void
    {
        $assignmentKey = $scheduledFor?->toDateString() ?? EmployeeTraining::UNSCHEDULED_ASSIGNMENT_KEY;
        $exists = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('training_id', $training->id)
            ->where('assignment_key', $assignmentKey)
            ->whereIn('status', [
                EmployeeTrainingStatus::Scheduled->value,
                EmployeeTrainingStatus::Completed->value,
            ])
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'training_id' => ['Employee already has an open or completed assignment for this training on that date.'],
            ]);
        }
    }

    private function isAssignmentConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'uq_emp_training_assignment_key')
            || str_contains($message, 'employee_trainings_employee_id_training_id_assignment_key_unique')
            || str_contains($message, 'employee_trainings.employee_id, employee_trainings.training_id');
    }
}
