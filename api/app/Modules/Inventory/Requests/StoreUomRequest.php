<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    public function rules(): array
    {
        $uom = $this->route('uom');
        $ignoreId = is_object($uom) ? $uom->id : null;

        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('uoms', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
