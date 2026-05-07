<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\Auth\Models\Role;
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
            'email'        => ['nullable', 'email', 'max:255'],
            'role_id'      => ['nullable', 'string'],
            'send_welcome' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        if (! empty($data['role_id'])) {
            $id = Role::tryDecodeHash((string) $data['role_id']);
            if ($id === null) {
                abort(422, 'Invalid role_id.');
            }
            $data['role_id'] = $id;
        }
        return $data;
    }
}
