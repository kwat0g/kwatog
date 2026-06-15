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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeTrainingController
{
    public function __construct(private readonly EmployeeTrainingService $service) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $rows = EmployeeTraining::query()
            ->with(['employee', 'training'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25));

        return EmployeeTrainingResource::collection($rows);
    }

    public function store(AssignEmployeeTrainingRequest $request, Employee $employee): JsonResponse
    {
        /** @var Training $training */
        $training = Training::query()->where('id', app('hashids')->decode($request->validated()['training_id'])[0] ?? 0)->firstOrFail();

        $rec = $this->service->assign(
            $employee,
            $training,
            $request->filled('scheduled_for') ? Carbon::parse($request->validated()['scheduled_for']) : null,
            $request->user(),
        );

        return (new EmployeeTrainingResource($rec))->response()->setStatusCode(201);
    }

    public function complete(CompleteEmployeeTrainingRequest $request, EmployeeTraining $record): EmployeeTrainingResource
    {
        $rec = $this->service->recordCompletion(
            $record,
            Carbon::parse($request->validated()['completed_at']),
            $request->validated()['certificate_path'] ?? null,
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
}
