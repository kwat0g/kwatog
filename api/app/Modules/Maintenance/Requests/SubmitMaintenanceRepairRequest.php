<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;

class SubmitMaintenanceRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete')
            || MaintenanceWorkAccess::supervisorMayManage($this->route('workOrder'), $this->user());
    }

    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string', 'max:5000'],
            'repair_action' => ['required', 'string', 'max:5000'],
            'functional_test_result' => ['required', 'string', 'max:5000'],
        ];
    }
}
