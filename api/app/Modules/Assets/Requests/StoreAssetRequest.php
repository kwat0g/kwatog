<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use App\Modules\Assets\Enums\AssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.create');
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:200'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'category'          => ['required', Rule::in(AssetCategory::values())],
            'department_id'     => ['nullable', 'integer', 'exists:departments,id'],
            'acquisition_date'  => ['required', 'date', 'before_or_equal:today'],
            'acquisition_cost'  => ['required', 'decimal:0,2', 'min:0'],
            'useful_life_years' => ['required', 'integer', 'min:1', 'max:100'],
            'salvage_value'     => ['nullable', 'decimal:0,2', 'min:0'],
            'location'          => ['nullable', 'string', 'max:100'],
        ];
    }
}
