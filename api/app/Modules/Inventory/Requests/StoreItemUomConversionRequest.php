<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'from_uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')->whereNull('deleted_at')],
            'to_uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')->whereNull('deleted_at')],
            'factor' => ['bail', 'required', 'numeric', 'gt:0', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $factor = $this->input('factor');
        if (is_string($factor) || is_int($factor) || is_float($factor)) {
            $factor = trim((string) $factor);
            if (preg_match('/^(\d+)(?:\.(\d+))?$/', $factor, $parts) === 1) {
                $integer = ltrim($parts[1], '0') ?: '0';
                $fraction = rtrim($parts[2] ?? '', '0');
                $normalized = $fraction === '' ? $integer : "{$integer}.{$fraction}";
                if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $normalized) === 1) {
                    [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
                    $normalized = $whole.'.'.str_pad($decimal, 6, '0');
                }

                $this->merge(['factor' => $normalized]);
            }
        }

        foreach (['from_uom_id', 'to_uom_id'] as $field) {
            $value = $this->input($field);
            $id = is_string($value) || is_int($value)
                ? HashIdFilter::decode($value, Uom::class)
                : null;

            $this->merge([$field => $id]);
        }
    }

    public function messages(): array
    {
        return [
            'factor.gt' => 'Conversion factor must be greater than zero.',
            'factor.regex' => 'Conversion factor must fit 12 integer and 6 decimal places.',
            'from_uom_id.exists' => 'The selected source unit is unavailable.',
            'to_uom_id.exists' => 'The selected target unit is unavailable.',
        ];
    }
}
