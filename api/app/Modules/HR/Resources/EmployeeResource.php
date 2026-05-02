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

        return [
            'id'           => $this->hash_id,
            'employee_no'  => $this->employee_no,
            'first_name'   => $this->first_name,
            'middle_name'  => $this->middle_name,
            'last_name'    => $this->last_name,
            'suffix'       => $this->suffix,
            'full_name'    => $this->full_name,

            'birth_date'   => optional($this->birth_date)->toDateString(),
            'gender'       => $this->gender?->value,
            'civil_status' => $this->civil_status?->value,
            'nationality'  => $this->nationality,
            'photo_path'   => $this->photo_path,

            'address' => [
                'street'    => $this->street_address,
                'barangay'  => $this->barangay,
                'city'      => $this->city,
                'province'  => $this->province,
                'zip_code'  => $this->zip_code,
            ],
            'contact' => [
                'mobile_number'              => $this->mobile_number,
                'email'                      => $this->email,
                'emergency_contact_name'     => $this->emergency_contact_name,
                'emergency_contact_relation' => $this->emergency_contact_relation,
                'emergency_contact_phone'    => $this->emergency_contact_phone,
            ],

            'status'           => $this->status?->value,
            'employment_type'  => $this->employment_type?->value,
            'pay_type'         => $this->pay_type?->value,
            'date_hired'       => optional($this->date_hired)->toDateString(),
            'date_regularized' => optional($this->date_regularized)->toDateString(),
            'basic_monthly_salary' => $this->basic_monthly_salary,
            'daily_rate'           => $this->daily_rate,

            'bank_name'       => $this->bank_name,

            // Sensitive fields — masked unless self or sensitive permission.
            'sss_no'          => $this->maskField($this->sss_no, $user),
            'philhealth_no'   => $this->maskField($this->philhealth_no, $user),
            'pagibig_no'      => $this->maskField($this->pagibig_no, $user),
            'tin'             => $this->maskField($this->tin, $user),
            'bank_account_no' => $this->maskField($this->bank_account_no, $user),

            'department' => new DepartmentResource($this->whenLoaded('department')),
            'position'   => new PositionResource($this->whenLoaded('position')),
            'user'       => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->hash_id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            'employment_history' => EmploymentHistoryResource::collection($this->whenLoaded('employmentHistory')),
            'documents'          => EmployeeDocumentResource::collection($this->whenLoaded('documents')),
            'property'           => EmployeePropertyResource::collection($this->whenLoaded('property')),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }

    private function maskField(?string $value, $user): ?string
    {
        if ($value === null || $value === '') return null;
        if ($user) {
            // Self-view: employee can see their own data.
            if ((int) $user->employee_id === (int) $this->id) return $value;
            if (method_exists($user, 'hasPermission') && $user->hasPermission('hr.employees.view_sensitive')) {
                return $value;
            }
            // System admin override.
            if ($user->role && $user->role->slug === 'system_admin') return $value;
        }
        $len = mb_strlen($value);
        if ($len <= 4) return str_repeat('•', $len);
        return str_repeat('•', $len - 4).mb_substr($value, -4);
    }
}
