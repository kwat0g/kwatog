<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollAnomalyFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'flag_type'          => $this->flag_type instanceof \BackedEnum ? $this->flag_type->value : (string) $this->flag_type,
            'details'            => $this->details ?? [],
            'is_resolved'        => (bool) $this->is_resolved,
            'resolution_remarks' => $this->resolution_remarks,
            'resolved_at'        => optional($this->resolved_at)->toIso8601String(),
            'resolved_by'        => $this->whenLoaded('resolver', fn () => $this->resolver ? [
                'id'   => $this->resolver->hash_id,
                'name' => $this->resolver->name,
            ] : null),
            'employee'           => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'name'        => method_exists($this->employee, 'getFullNameAttribute')
                    ? $this->employee->full_name
                    : trim(($this->employee->first_name ?? '').' '.($this->employee->last_name ?? '')),
            ] : null),
            'payroll_id'         => $this->payroll ? $this->payroll->hash_id : null,
            'created_at'         => optional($this->created_at)->toIso8601String(),
        ];
    }
}
