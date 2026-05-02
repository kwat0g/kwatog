<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Modules\Production\Enums\MachineDowntimeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PauseWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.work_orders.lifecycle') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason'   => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(MachineDowntimeCategory::values())],
        ];
    }
}
