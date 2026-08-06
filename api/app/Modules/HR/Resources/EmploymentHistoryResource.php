<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class EmploymentHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canViewSensitive = $user && (
            (int) $user->employee_id === (int) $this->employee_id
            || $user->hasPermission('hr.employees.view_sensitive')
        );
        $sensitiveKeys = ['basic_monthly_salary', 'semi_monthly_rate', 'salary'];

        return [
            'id' => $this->hash_id,
            'change_type' => $this->change_type,
            'from_value' => $canViewSensitive ? $this->from_value : Arr::except($this->from_value ?? [], $sensitiveKeys),
            'to_value' => $canViewSensitive ? $this->to_value : Arr::except($this->to_value ?? [], $sensitiveKeys),
            'effective_date' => optional($this->effective_date)->toDateString(),
            'remarks' => $this->remarks,
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->hash_id, 'name' => $this->approver->name,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
