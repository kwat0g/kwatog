<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Modules\MRP\Enums\MachineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMachineStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.machines.transition') ?? false;
    }

    public function rules(): array
    {
        return [
            'to'     => ['required', Rule::in(MachineStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
