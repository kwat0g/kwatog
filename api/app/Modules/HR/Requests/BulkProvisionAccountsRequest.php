<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class BulkProvisionAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.provision_account') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['required', 'string'],
            'send_welcome'   => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function decodedEmployeeIds(): array
    {
        $ids = [];
        foreach ((array) $this->validated('employee_ids') as $hash) {
            $id = Employee::tryDecodeHash((string) $hash);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
