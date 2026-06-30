<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.settings.manage') ?? false;
    }

    public function rules(): array
    {
        $key = $this->route('key');

        $base = ['value' => ['present']];

        $securityRules = [
            'security.max_login_attempts'      => ['value' => ['required', 'integer', 'min:1', 'max:20']],
            'security.lockout_minutes'          => ['value' => ['required', 'integer', 'min:1', 'max:60']],
            'security.password_history_depth'   => ['value' => ['required', 'integer', 'min:0', 'max:10']],
            'security.password_min_length'      => ['value' => ['required', 'integer', 'min:6', 'max:32']],
            'security.session_timeout_employee' => ['value' => ['required', 'integer', 'min:5', 'max:120']],
            'security.session_timeout_default'  => ['value' => ['required', 'integer', 'min:5', 'max:120']],
            'security.password_expiry_days'     => ['value' => ['required', 'integer', 'min:0', 'max:365']],
        ];

        return $securityRules[$key] ?? $base;
    }

    public function messages(): array
    {
        return [
            'value.min' => 'Value is below the allowed minimum.',
            'value.max' => 'Value exceeds the allowed maximum.',
        ];
    }
}
