<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Support\Str;

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
            'status_label'     => Str::headline((string) ($this->status?->value ?? $this->status)),
            'certificate'       => $this->certificate_path ? [
                'name'         => $this->certificate_original_name ?: 'Training certificate',
                'mime_type'    => $this->certificate_mime_type,
                'size'         => $this->certificate_size,
                'uploaded_at'  => $this->certificate_uploaded_at?->toISOString(),
                'download_url' => route('hr.employee-trainings.certificate', ['record' => $this->hash_id]),
            ] : null,
            'notes'            => $this->notes,
            'last_alert_level' => $this->last_alert_level?->value,
            'last_alert_at'    => $this->last_alert_at?->toISOString(),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
