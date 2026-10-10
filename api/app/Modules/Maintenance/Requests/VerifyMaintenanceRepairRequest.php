<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyMaintenanceRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.verify');
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:5000'],
        ];
    }
}
