<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitReturnRequestItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return_management.dispose');
    }

    public function rules(): array
    {
        return ['split_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3']];
    }
}
