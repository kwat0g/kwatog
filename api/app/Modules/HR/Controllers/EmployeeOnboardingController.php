<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeOnboardingResource;
use App\Modules\HR\Services\OnboardingService;

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
        $this->onboarding->recompute($employee);
        return new EmployeeOnboardingResource(
            $this->onboarding->status($employee),
        );
    }
}
