<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEdgeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.edge_devices.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'string', 'max:100'],
            'location'   => ['nullable', 'string', 'max:100'],
            'machine_id' => ['nullable', 'string', 'max:100'], // hash id, decoded in service
            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }
}
