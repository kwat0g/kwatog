<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            // Accept HashIDs (resolved in controller) or raw integer IDs.
            'from_uom_id' => ['required'],
            'to_uom_id'   => ['required'],
            'factor'      => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'factor.gt' => 'Conversion factor must be greater than zero.',
        ];
    }
}
