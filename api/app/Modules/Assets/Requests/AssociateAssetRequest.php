<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use App\Common\Support\HashId;
use App\Modules\Assets\Enums\AssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssociateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.update');
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('target_id');
        if ($raw === null || $raw === '') {
            return;
        }

        $this->merge(['target_id' => HashId::decode((string) $raw) ?? 0]);
    }

    public function rules(): array
    {
        return [
            'target_type' => [
                'required',
                Rule::in([
                    AssetCategory::Machine->value,
                    AssetCategory::Mold->value,
                    AssetCategory::Vehicle->value,
                ]),
            ],
            // Null clears the existing association. The service validates the
            // target table selected by target_type.
            'target_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
