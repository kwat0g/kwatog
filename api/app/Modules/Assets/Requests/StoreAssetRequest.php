<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Common\Support\Money;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAssetRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.create');
    }

    protected function hashIdFields(): array
    {
        return [
            'department_id' => Department::class,
        ];
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
            'depreciation_method' => ['nullable', Rule::in(\App\Modules\Assets\Enums\DepreciationMethod::values())],
            'salvage_value'     => ['nullable', 'decimal:0,2', 'min:0'],
            'location'          => ['nullable', 'string', 'max:100'],
            'insurance_policy_no' => ['nullable', 'string', 'max:100'],
            'insurance_provider'  => ['nullable', 'string', 'max:150'],
            'insurance_expiry'    => ['nullable', 'date'],
            'insured_value'       => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    /**
     * Salvage value is residual value, so it cannot exceed what the asset cost.
     * Without the bound the model clamps the depreciable base to zero
     * (`Asset::getMonthlyDepreciationAttribute()`), so the asset is accepted and
     * then silently never depreciates — no expense, no schedule, nothing raised.
     * Keyed to `salvage_value` rather than thrown from the service so the form
     * highlights the field the operator can actually correct.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $salvage = $this->input('salvage_value');
            $cost = $this->input('acquisition_cost');
            if ($salvage === null || trim((string) $salvage) === '') {
                return;
            }
            // A non-numeric value on either field is already reported by its own
            // rule; comparing them here would add a second, confusing error.
            if (! is_numeric($salvage) || ! is_numeric($cost)) {
                return;
            }
            if (Money::gt((string) $salvage, (string) $cost)) {
                $v->errors()->add(
                    'salvage_value',
                    'Salvage value cannot exceed the acquisition cost.',
                );
            }
        });
    }
}
