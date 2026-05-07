<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAccountStatusResource extends JsonResource
{
    /** The resource is the array returned by UserProvisioningService::accountStatusForEmployee. */
    public function toArray($request): array
    {
        // Pass-through; user_id is already a hash_id from the service.
        return $this->resource;
    }
}
