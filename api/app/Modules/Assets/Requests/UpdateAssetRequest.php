<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.update');
    }

    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'required', 'string', 'max:200'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'department_id'     => ['nullable', 'integer', 'exists:departments,id'],
            'useful_life_years' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'salvage_value'     => ['nullable', 'decimal:0,2', 'min:0'],
            'location'          => ['nullable', 'string', 'max:100'],
        ];
    }
}
