<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.products.manage') ?? false;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;
        return [
            'part_number'     => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9-]{2,30}$/', Rule::unique('products', 'part_number')->ignore($productId)],
            'name'            => ['sometimes', 'required', 'string', 'max:200'],
            'description'     => ['nullable', 'string', 'max:1000'],
            'unit_of_measure' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::exists('uoms', 'code')->whereNull('deleted_at'),
            ],
            'standard_cost'   => ['sometimes', 'required', 'decimal:0,2', 'min:0'],
            'is_active'       => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('unit_of_measure')) {
            $this->merge(['unit_of_measure' => strtoupper(trim((string) $this->input('unit_of_measure')))]);
        }
    }
}
