<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverUpdateStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['loading', 'in_transit', 'delivered'])],
        ];
    }
}
