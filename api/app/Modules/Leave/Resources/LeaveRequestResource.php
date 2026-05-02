<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'leave_request_no' => $this->leave_request_no,
            'employee'         => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'           => $this->employee->hash_id,
                'employee_no'  => $this->employee->employee_no,
                'full_name'    => $this->employee->full_name,
                'department'   => $this->employee->department?->name,
            ] : null),
            'leave_type'       => $this->whenLoaded('leaveType', fn () => $this->leaveType ? [
                'id'   => $this->leaveType->hash_id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
            ] : null),
            'start_date'       => optional($this->start_date)->toDateString(),
            'end_date'         => optional($this->end_date)->toDateString(),
            'days'             => (string) $this->days,
            'reason'           => $this->reason,
            'document_path'    => $this->document_path,
            'status'           => $this->status?->value,
            'dept_approver'    => $this->whenLoaded('deptApprover', fn () => $this->deptApprover ? [
                'id' => $this->deptApprover->hash_id, 'name' => $this->deptApprover->name,
            ] : null),
            'dept_approved_at' => optional($this->dept_approved_at)->toIso8601String(),
            'hr_approver'      => $this->whenLoaded('hrApprover', fn () => $this->hrApprover ? [
                'id' => $this->hrApprover->hash_id, 'name' => $this->hrApprover->name,
            ] : null),
            'hr_approved_at'   => optional($this->hr_approved_at)->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
