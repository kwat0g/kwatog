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
        foreach (['is_dismissed', 'is_read'] as $filter) {
            if (! $this->has($filter)) {
                continue;
            }

            $value = $this->input($filter);
            if ($value === 'true') {
                $this->merge([$filter => true]);
            } elseif ($value === 'false') {
                $this->merge([$filter => false]);
            }
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
            'is_read' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
