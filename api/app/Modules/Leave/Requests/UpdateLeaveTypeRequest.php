<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveTypeRequest extends FormRequest
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
        $id = $this->route('leaveType')?->id;
        return [
            'name'                         => ['sometimes', 'string', 'max:100'],
            'code'                         => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9_]+$/', Rule::unique('leave_types', 'code')->ignore($id)],
            'default_balance'              => ['sometimes', 'numeric', 'min:0', 'max:999.9'],
            'is_paid'                      => ['sometimes', 'boolean'],
            'requires_document'            => ['sometimes', 'boolean'],
            'is_convertible_on_separation' => ['sometimes', 'boolean'],
            'is_convertible_year_end'      => ['sometimes', 'boolean'],
            'conversion_rate'              => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9.99'],
            'is_active'                    => ['sometimes', 'boolean'],
        ];
    }
}
