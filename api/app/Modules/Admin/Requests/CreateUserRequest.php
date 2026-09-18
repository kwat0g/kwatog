<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Modules\Auth\Models\Role;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'  => ['required', 'string'],
            'email'        => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'role_id'      => ['required', 'string'],
            'send_welcome' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Server-side eligibility gate so a stale picker option or a hand-crafted
     * request can never attach an account to an employee who must not have
     * one: no account yet, active or probationary, not separated.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $employee = $this->decodedEmployee();
            if ($employee === null) {
                $validator->errors()->add('employee_id', 'The selected employee is no longer available.');

                return;
            }

            if ($employee->user()->exists()) {
                $validator->errors()->add('employee_id', 'This employee already has a user account.');
            }

            // The model casts employment_type to an enum instance — compare
            // cases, not string values.
            $eligible = [EmploymentType::Regular, EmploymentType::Probationary];
            if (! in_array($employee->employment_type, $eligible, true)) {
                $validator->errors()->add(
                    'employee_id',
                    'Only regular and probationary employees can be given a user account.',
                );
            }

            if ($employee->status === \App\Modules\HR\Enums\EmployeeStatus::Terminated) {
                $validator->errors()->add('employee_id', 'This employee is separated and cannot be given a user account.');
            }

            // Every account needs a login email. A typed email wins; otherwise
            // the employee's own HR email is used — if neither exists the
            // admin must type one (no silent generation from the admin path).
            $typed = strtolower(trim((string) $this->input('email')));
            $hasTypedEmail = $typed !== '' && filter_var($typed, FILTER_VALIDATE_EMAIL) !== false;
            $hrEmail = trim((string) $employee->email);
            $hasHrEmail = $hrEmail !== '' && filter_var($hrEmail, FILTER_VALIDATE_EMAIL) !== false;
            if (! $hasTypedEmail && ! $hasHrEmail) {
                $validator->errors()->add('email', 'This employee has no email on record. Enter a login email.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already used by another user account.',
        ];
    }

    /**
     * @return array{employee_id: int, email: ?string, role_id: int, send_welcome: bool}
     */
    public function payload(): array
    {
        $data = $this->validated();
        $roleId = Role::tryDecodeHash((string) $data['role_id']);
        abort_if($roleId === null, 422, 'Invalid role_id.');

        $employeeId = Employee::tryDecodeHash((string) $data['employee_id']);
        abort_if($employeeId === null, 422, 'Invalid employee_id.');

        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        return [
            'employee_id'  => $employeeId,
            'email'        => $email !== '' ? $email : null,
            'role_id'      => $roleId,
            'send_welcome' => (bool) ($data['send_welcome'] ?? true),
        ];
    }

    private function decodedEmployee(): ?Employee
    {
        $raw = $this->input('employee_id');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $id = Employee::tryDecodeHash($raw);

        return $id === null ? null : Employee::find($id);
    }
}
