<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Modules\MRP\Enums\MachineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.machines.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('machine')?->id;
        return [
            'machine_code'             => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                                            Rule::unique('machines', 'machine_code')->ignore($id)],
            'name'                     => ['sometimes', 'required', 'string', 'max:100'],
            'tonnage'                  => ['nullable', 'integer', 'min:0', 'max:5000'],
            'machine_type'             => ['nullable', 'string', 'max:50'],
            'operators_required'       => ['nullable', 'decimal:0,1', 'min:0'],
            'available_hours_per_day'  => ['nullable', 'decimal:0,1', 'min:0', 'max:24'],
        ];
    }
}
