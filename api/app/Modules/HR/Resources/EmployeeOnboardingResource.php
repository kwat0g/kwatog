<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeOnboardingResource extends JsonResource
{
    public function toArray($request): array
    {
        // Resource holds the array returned by OnboardingService::status.
        return $this->resource;
    }
}
