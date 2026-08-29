<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Common\Support\Money;
use App\Modules\Assets\Models\Asset;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAssetRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.update');
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
            'name'              => ['sometimes', 'required', 'string', 'max:200'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'department_id'     => ['nullable', 'integer', 'exists:departments,id'],
            'useful_life_years' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'salvage_value'     => ['nullable', 'decimal:0,2', 'min:0'],
            'location'          => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Same residual-value bound as create, compared against the stored
     * acquisition cost because this form cannot change it.
     *
     * An unchanged value is deliberately allowed through even when it already
     * breaks the bound: acquisition cost is not editable here and salvage is
     * frozen once depreciation history exists, so rejecting the resubmitted
     * value would leave a pre-existing bad row permanently un-editable — the
     * operator could not fix its name or department either. Any *change* still
     * has to satisfy the bound, so a bad row can only move toward legality.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $salvage = $this->input('salvage_value');
            if (! $this->has('salvage_value') || $salvage === null || trim((string) $salvage) === '') {
                return;
            }
            if (! is_numeric($salvage)) {
                return;
            }
            $asset = $this->route('asset');
            if (! $asset instanceof Asset) {
                return;
            }
            if (Money::cmp((string) $salvage, (string) $asset->salvage_value) === 0) {
                return;
            }
            if (Money::gt((string) $salvage, (string) $asset->acquisition_cost)) {
                $v->errors()->add(
                    'salvage_value',
                    'Salvage value cannot exceed the acquisition cost of '.$asset->acquisition_cost.'.',
                );
            }
        });
    }
}
