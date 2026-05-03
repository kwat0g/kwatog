<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.create');
    }

    public function rules(): array
    {
        return [
            'maintainable_type' => ['required', Rule::in(MaintainableType::values())],
            'maintainable_id'   => ['required', 'integer', 'min:1'],
            'type'              => ['required', Rule::in(MaintenanceWorkOrderType::values())],
            'priority'          => ['required', Rule::in(MaintenancePriority::values())],
            'description'       => ['required', 'string', 'max:5000'],
        ];
    }
}
