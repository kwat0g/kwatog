<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canViewSensitive = $this->canViewSensitive($user);

        return [
            'id' => $this->hash_id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,

            'birth_date' => optional($this->birth_date)->toDateString(),
            'gender' => $this->gender?->value,
            'gender_label' => $this->gender?->label(),
            'civil_status' => $this->civil_status?->value,
            'civil_status_label' => $this->civil_status?->label(),
            'nationality' => $this->nationality,
            'photo_path' => $this->photo_path,
            // Photo is served through the authenticated /photo endpoint — never
            // via a public /storage/ URL.
            'photo_url' => $this->photo_path ? "/api/v1/hr/employees/{$this->hash_id}/photo" : null,

            'address' => [
                'street' => $this->street_address,
                'barangay' => $this->barangay,
                'city' => $this->city,
                'province' => $this->province,
                'zip_code' => $this->zip_code,
            ],
            'contact' => [
                'mobile_number' => $this->mobile_number,
                // The user account is the authoritative login/contact fallback
                // when older employee rows have no email column value. Avoid
                // lazy-loading here; the relation is only consulted when the
                // resource caller explicitly included it.
                'email' => $this->email ?: ($this->relationLoaded('user') ? $this->user?->email : null),
                'emergency_contact_name' => $this->emergency_contact_name,
                'emergency_contact_relation' => $this->emergency_contact_relation,
                'emergency_contact_phone' => $this->emergency_contact_phone,
            ],

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'employment_type' => $this->employment_type?->value,
            'employment_type_label' => $this->employment_type?->label(),
            'pay_type' => $this->pay_type?->value,
            'pay_type_label' => $this->pay_type?->label(),
            'date_hired' => optional($this->date_hired)->toDateString(),
            'date_regularized' => optional($this->date_regularized)->toDateString(),
            'basic_monthly_salary' => $canViewSensitive ? $this->basic_monthly_salary : null,
            'semi_monthly_rate' => $canViewSensitive ? $this->semi_monthly_rate : null,

            'bank_name' => $this->bank_name,

            // Sensitive fields — masked unless self or sensitive permission.
            'sss_no' => $this->maskField($this->sss_no, $user),
            'philhealth_no' => $this->maskField($this->philhealth_no, $user),
            'pagibig_no' => $this->maskField($this->pagibig_no, $user),
            'tin' => $this->maskField($this->tin, $user),
            'bank_account_no' => $this->maskField($this->bank_account_no, $user),

            'department' => new DepartmentResource($this->whenLoaded('department')),
            'position' => new PositionResource($this->whenLoaded('position')),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->hash_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            'employment_history' => EmploymentHistoryResource::collection($this->whenLoaded('employmentHistory')),
            'documents' => $this->when(
                $user?->hasPermission('hr.employees.documents.view') ?? false,
                fn () => EmployeeDocumentResource::collection($this->whenLoaded('documents')),
            ),
            'property' => EmployeePropertyResource::collection($this->whenLoaded('property')),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }

    private function maskField(?string $value, $user): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($user) {
            // Self-view: employee can see their own data.
            if ((int) $user->employee_id === (int) $this->id) {
                return $value;
            }
            if (method_exists($user, 'hasPermission') && $user->hasPermission('hr.employees.view_sensitive')) {
                return $value;
            }
            // System admin override.
            if ($user->role && $user->role->slug === 'system_admin') {
                return $value;
            }
        }
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4).mb_substr($value, -4);
    }

    private function canViewSensitive($user): bool
    {
        if (! $user) {
            return false;
        }

        return (int) $user->employee_id === (int) $this->id
            || $user->hasPermission('hr.employees.view_sensitive');
    }
}
