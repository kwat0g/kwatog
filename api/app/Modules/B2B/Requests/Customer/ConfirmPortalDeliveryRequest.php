<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPortalDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_name'     => ['nullable', 'string', 'max:100'],
            'receiver_position' => ['nullable', 'string', 'max:100'],
            'delivery_remarks'  => ['nullable', 'string', 'max:500'],
        ];
    }
}
