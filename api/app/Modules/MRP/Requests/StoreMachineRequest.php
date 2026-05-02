<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Modules\MRP\Enums\MachineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.machines.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'machine_code'             => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/', 'unique:machines,machine_code'],
            'name'                     => ['required', 'string', 'max:100'],
            'tonnage'                  => ['nullable', 'integer', 'min:0', 'max:5000'],
            'machine_type'             => ['nullable', 'string', 'max:50'],
            'operators_required'       => ['nullable', 'decimal:0,1', 'min:0'],
            'available_hours_per_day'  => ['nullable', 'decimal:0,1', 'min:0', 'max:24'],
            'status'                   => ['nullable', Rule::in(MachineStatus::values())],
        ];
    }
}
