<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeOnboardingResource;
use App\Modules\HR\Services\OnboardingService;
use Illuminate\Http\Request;

class EmployeeOnboardingController
{
    public function __construct(
        private readonly OnboardingService $onboarding,
    ) {}

    public function show(Employee $employee): EmployeeOnboardingResource
    {
        return new EmployeeOnboardingResource(
            $this->onboarding->status($employee),
        );
    }

    public function recompute(Employee $employee): EmployeeOnboardingResource
    {
        return new EmployeeOnboardingResource(
            $this->onboarding->recomputeStatus($employee),
        );
    }

    public function markDepartmentTeamNotified(Request $request, Employee $employee): EmployeeOnboardingResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->onboarding->markDepartmentTeamNotified($employee, $actor);

        return new EmployeeOnboardingResource($this->onboarding->status($employee));
    }
}
