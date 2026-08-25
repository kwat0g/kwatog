<?php

declare(strict_types=1);

namespace App\Common\Requests;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAlertsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_dismissed')) {
            return;
        }

        $value = $this->input('is_dismissed');
        if ($value === 'true') {
            $this->merge(['is_dismissed' => true]);
        } elseif ($value === 'false') {
            $this->merge(['is_dismissed' => false]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('alerts.view') === true;
    }

    public function rules(): array
    {
        return [
            'severity' => ['sometimes', 'array'],
            'severity.*' => [Rule::in(AlertSeverity::values())],
            'type' => ['sometimes', 'array'],
            'type.*' => [Rule::in(AlertType::values())],
            'entity_type' => ['sometimes', 'string', 'max:100'],
            'is_dismissed' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
