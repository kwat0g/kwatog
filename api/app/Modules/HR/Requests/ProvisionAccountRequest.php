<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.provision_account') ?? false;
    }

    public function rules(): array
    {
        return [
            // The recipient and role are policy decisions made from the
            // employee record and server settings. Never accept either from
            // an HR form or API caller.
            'email'        => ['prohibited'],
            'role_id'      => ['prohibited'],
            'send_welcome' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
