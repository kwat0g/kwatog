<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeTrainingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->hash_id,
            'employee'         => $this->whenLoaded('employee', fn() => $this->employee ? [
                'id'        => $this->employee->hash_id,
                'full_name' => $this->employee->full_name,
            ] : null),
            'training'         => $this->whenLoaded('training', fn() => $this->training ? [
                'id'              => $this->training->hash_id,
                'name'            => $this->training->name,
                'validity_months' => $this->training->validity_months,
            ] : null),
            'scheduled_for'    => $this->scheduled_for?->toDateString(),
            'completed_at'     => $this->completed_at?->toDateString(),
            'expires_at'       => $this->expires_at?->toDateString(),
            'status'           => $this->status?->value,
            'certificate_path' => $this->certificate_path,
            'notes'            => $this->notes,
            'last_alert_level' => $this->last_alert_level?->value,
            'last_alert_at'    => $this->last_alert_at?->toISOString(),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
