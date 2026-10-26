<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'theme_mode'        => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'sidebar_peek'      => ['sometimes', 'boolean'],
            'sidebar_expand_all' => ['sometimes', 'boolean'],
            'table_preferences' => [
                'sometimes', 'array', 'max:200',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }
                    foreach ($value as $tableKey => $preference) {
                        if (! is_string($tableKey) || strlen($tableKey) > 200) {
                            $fail('Each table preference must have a key no longer than 200 characters.');
                            continue;
                        }
                        if ($preference === null) {
                            continue;
                        }
                        if (! is_array($preference) || array_diff(array_keys($preference), ['density', 'hiddenColumns'])) {
                            $fail('Each table preference may contain only density and hiddenColumns.');
                            continue;
                        }
                        if (array_key_exists('density', $preference)
                            && ! in_array($preference['density'], ['compact', 'default', 'spacious'], true)) {
                            $fail('Table density must be compact, default, or spacious.');
                        }
                        if (array_key_exists('hiddenColumns', $preference)
                            && (! is_array($preference['hiddenColumns'])
                                || count($preference['hiddenColumns']) > 100
                                || array_filter($preference['hiddenColumns'], fn ($column) => ! is_string($column) || strlen($column) > 120))) {
                            $fail('hiddenColumns must be a list of at most 100 column keys, each no longer than 120 characters.');
                        }
                    }
                },
            ],
        ];
    }
}
