<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Modules\Admin\Enums\AdminUserStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'role_id' => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(AdminUserStatus::values())],
            'sort' => ['nullable', Rule::in(['name', 'email', 'last_activity', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $roleHash = $this->input('role_id');
            if (is_string($roleHash) && $roleHash !== '') {
                $roleId = Role::tryDecodeHash($roleHash);
                if ($roleId === null || ! Role::query()->whereKey($roleId)->exists()) {
                    $validator->errors()->add('role_id', 'The selected role is no longer available.');
                }
            }

            $departmentHash = $this->input('department_id');
            if (is_string($departmentHash) && $departmentHash !== '') {
                $departmentId = Department::tryDecodeHash($departmentHash);
                if (
                    $departmentId === null
                    || ! Department::query()->whereKey($departmentId)->where('is_active', true)->exists()
                ) {
                    $validator->errors()->add('department_id', 'The selected department is no longer available.');
                }
            }
        });
    }
}
