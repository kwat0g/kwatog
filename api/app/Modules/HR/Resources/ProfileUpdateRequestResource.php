<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\HR\Enums\ProfileUpdateStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileUpdateRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'      => $this->hash_id,
            'status'  => $this->status,
            'status_label' => ProfileUpdateStatus::tryFrom((string) $this->status)?->label() ?? (string) $this->status,
            'requires_finance' => (bool) $this->requires_finance,
            'changes' => $this->redactedChanges($request),
            'note'    => $this->note,
            'employee' => $this->relationLoaded('employee') && $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
                'department'  => $this->employee->relationLoaded('department') && $this->employee->department
                    ? ['id' => $this->employee->department->hash_id, 'name' => $this->employee->department->name]
                    : null,
            ] : null,
            'requester' => $this->relationLoaded('requester') && $this->requester ? [
                'id'    => $this->requester->hash_id,
                'name'  => $this->requester->name,
                'email' => $this->requester->email,
            ] : null,
            'reviewer' => $this->relationLoaded('reviewer') && $this->reviewer ? [
                'id'    => $this->reviewer->hash_id,
                'name'  => $this->reviewer->name,
            ] : null,
            'reviewed_at'    => optional($this->reviewed_at)->toIso8601String(),
            'review_remarks' => $this->review_remarks,
            'finance_reviewer' => $this->relationLoaded('financeReviewer') && $this->financeReviewer ? [
                'id'   => $this->financeReviewer->hash_id,
                'name' => $this->financeReviewer->name,
            ] : null,
            'finance_reviewed_at' => optional($this->finance_reviewed_at)->toIso8601String(),
            'finance_remarks'     => $this->finance_remarks,
            'created_at'     => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function redactedChanges($request): array
    {
        $changes = (array) $this->changes;
        $user = $request->user();
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $canSeeFull = $user?->hasPermission('hr.employees.view_sensitive')
            || $user?->hasPermission('hr.profile_updates.finance_review')
            || ($employee && (int) $user?->employee_id === (int) $employee->id);

        if (! $canSeeFull && isset($changes['bank_account_no']) && is_string($changes['bank_account_no'])) {
            $value = $changes['bank_account_no'];
            $changes['bank_account_no'] = strlen($value) <= 4
                ? str_repeat('*', strlen($value))
                : str_repeat('*', strlen($value) - 4).substr($value, -4);
        }

        return $changes;
    }
}
