<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Requests\AssignEmployeeTrainingRequest;
use App\Modules\HR\Requests\CompleteEmployeeTrainingRequest;
use App\Modules\HR\Resources\EmployeeTrainingResource;
use App\Modules\HR\Services\EmployeeTrainingService;
use App\Modules\HR\Services\TrainingEvidenceService;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeTrainingController
{
    public function __construct(
        private readonly EmployeeTrainingService $service,
        private readonly TrainingEvidenceService $evidence,
    ) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $employee = EmployeeCompetenceScope::employee($employee, $request->user());
        $rows = EmployeeTraining::query()
            ->with(['employee', 'training'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return EmployeeTrainingResource::collection($rows);
    }

    public function all(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'signal' => ['nullable', 'in:expired,upcoming'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = EmployeeTraining::query()->with(['employee', 'training']);
        EmployeeCompetenceScope::trainings($rows, $request->user());

        if (($filters['signal'] ?? null) === 'expired') {
            $rows->expiredForActiveEmployees();
        } elseif (($filters['signal'] ?? null) === 'upcoming') {
            $rows->upcomingOrExpiringForActiveEmployees();
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['search'])).'%';
            $rows->where(fn ($query) => $query
                ->whereHas('employee', fn ($employee) => $employee
                    ->where('employee_no', 'ilike', $term)
                    ->orWhere('first_name', 'ilike', $term)
                    ->orWhere('last_name', 'ilike', $term))
                ->orWhereHas('training', fn ($training) => $training->where('name', 'ilike', $term)));
        }

        return EmployeeTrainingResource::collection(
            $rows->orderByDesc('scheduled_for')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))
        );
    }

    public function show(Request $request, EmployeeTraining $record): EmployeeTrainingResource
    {
        $record = EmployeeCompetenceScope::training($record, $request->user());
        $record->loadMissing(['employee', 'training']);

        return new EmployeeTrainingResource($record);
    }

    public function store(AssignEmployeeTrainingRequest $request, Employee $employee): JsonResponse
    {
        $employee = EmployeeCompetenceScope::employee($employee, $request->user());
        /** @var Training $training */
        $training = Training::query()->where('id', app('hashids')->decode($request->validated()['training_id'])[0] ?? 0)->firstOrFail();

        $rec = $this->service->assign(
            $employee,
            $training,
            $request->filled('scheduled_for') ? Carbon::parse($request->validated()['scheduled_for']) : null,
            $request->user(),
            $request->validated()['notes'] ?? null,
        );

        return (new EmployeeTrainingResource($rec))->response()->setStatusCode(201);
    }

    public function complete(CompleteEmployeeTrainingRequest $request, EmployeeTraining $record): EmployeeTrainingResource
    {
        $rec = $this->service->recordCompletion(
            $record,
            Carbon::parse($request->validated()['completed_at']),
            $request->file('certificate'),
            $request->user(),
        );

        return new EmployeeTrainingResource($rec);
    }

    public function cancel(Request $request, EmployeeTraining $record): EmployeeTrainingResource
    {
        abort_unless($request->user()?->can('hr.employees.trainings.manage'), 403);

        $rec = $this->service->cancel($record, $request->input('reason'), $request->user());
        return new EmployeeTrainingResource($rec);
    }

    public function download(Request $request, EmployeeTraining $record): StreamedResponse
    {
        $record = EmployeeCompetenceScope::training($record, $request->user());
        abort_unless($record->certificate_path, 404);

        return $this->evidence->download(
            $record->certificate_path,
            $record->certificate_original_name ?: 'training-certificate',
            $record->certificate_mime_type,
        );
    }
}
