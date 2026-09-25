<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportDeliveryNotArrivedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer_portal') !== null;
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'message' => ['nullable', 'string', 'max:2000']];
    }
}
