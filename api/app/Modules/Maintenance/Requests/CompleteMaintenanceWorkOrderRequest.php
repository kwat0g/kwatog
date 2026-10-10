<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;

class CompleteMaintenanceWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete')
            || MaintenanceWorkAccess::supervisorMayManage($this->route('workOrder'), $this->user());
    }

    public function rules(): array
    {
        return [
            'remarks'          => ['nullable', 'string', 'max:5000'],
        ];
    }
}
