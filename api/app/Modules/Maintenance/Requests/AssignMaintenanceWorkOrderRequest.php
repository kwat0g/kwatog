<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class AssignMaintenanceWorkOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.assign');
    }

    protected function hashIdFields(): array
    {
        return [
            'employee_id' => Employee::class,
        ];
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ];
    }
}
