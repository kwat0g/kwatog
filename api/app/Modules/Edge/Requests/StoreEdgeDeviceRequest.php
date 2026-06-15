<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use App\Modules\Edge\Enums\EdgeDeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEdgeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.edge_devices.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:100', 'unique:edge_devices,serial_number'],
            'name'          => ['required', 'string', 'max:100'],
            'device_type'   => ['required', Rule::in(EdgeDeviceType::values())],
            'location'      => ['nullable', 'string', 'max:100'],
            'machine_id'    => ['nullable', 'string', 'max:100'], // hash id, decoded in service
            'notes'         => ['nullable', 'string', 'max:2000'],
        ];
    }
}
