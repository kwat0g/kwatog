<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteMaintenanceWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete');
    }

    public function rules(): array
    {
        return [
            'remarks'          => ['nullable', 'string', 'max:5000'],
            'downtime_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
