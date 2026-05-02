<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leave.types.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('name')))
            $merge['name'] = trim((string) $this->input('name'));
        if (is_string($this->input('code')))
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        if (!empty($merge)) $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name'                         => ['required', 'string', 'max:100'],
            'code'                         => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9_]+$/', 'unique:leave_types,code'],
            'default_balance'              => ['required', 'numeric', 'min:0', 'max:999.9'],
            'is_paid'                      => ['boolean'],
            'requires_document'            => ['boolean'],
            'is_convertible_on_separation' => ['boolean'],
            'is_convertible_year_end'      => ['boolean'],
            'conversion_rate'              => ['nullable', 'numeric', 'min:0', 'max:9.99'],
            'is_active'                    => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Code must be uppercase letters, digits, or underscores (e.g. SL, VL, BL).',
        ];
    }
}
