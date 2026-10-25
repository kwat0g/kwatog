<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserInvite;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * WS-A.1 — Validate a request to issue a portal-account invite for an
 * employee. Translates client hash IDs to integer FKs.
 */
class CreateUserInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('auth.users.invite') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'role_id'     => ['nullable', 'string'],
            'email'       => ['required', 'email', 'max:191', 'unique:users,email'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $employeeId = Employee::tryDecodeHash((string) $this->input('employee_id', ''));
            if ($employeeId === null) {
                $v->errors()->add('employee_id', 'Unknown employee.');
                return;
            }
            $employee = Employee::query()->whereKey($employeeId)->first();
            if ($employee === null) {
                $v->errors()->add('employee_id', 'Unknown employee.');
                return;
            }

            // Cannot invite an employee who already has a portal account.
            if (User::query()->where('employee_id', $employee->id)->exists()) {
                $v->errors()->add('employee_id', 'This employee already has a portal account.');
            }

            // Cannot stack two pending invites for the same employee.
            $hasPending = UserInvite::query()
                ->where('employee_id', $employee->id)
                ->whereNull('used_at')
                ->whereNull('deleted_at')
                ->where('expires_at', '>', now())
                ->exists();
            if ($hasPending) {
                $v->errors()->add('employee_id', 'A pending invite already exists for this employee.');
            }

            if ($this->filled('role_id')) {
                $roleId = Role::tryDecodeHash((string) $this->input('role_id'));
                if ($roleId === null || ! Role::query()->whereKey($roleId)->exists()) {
                    $v->errors()->add('role_id', 'Unknown role.');
                }
            }
        });
    }

    /** @return array{employee_id:int, email:string, role_id?:int|null} */
    public function decoded(): array
    {
        $data = $this->validated();
        $out = [
            'employee_id' => (int) Employee::tryDecodeHash((string) $data['employee_id']),
            'email'       => trim((string) $data['email']),
        ];
        if (! empty($data['role_id'])) {
            $out['role_id'] = (int) Role::tryDecodeHash((string) $data['role_id']);
        }
        return $out;
    }
}
