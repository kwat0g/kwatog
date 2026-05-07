<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:120'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'role_id'      => ['required', 'string'],
            'send_welcome' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, email: string, role_id: int, send_welcome: bool}
     */
    public function payload(): array
    {
        $data = $this->validated();
        $roleId = Role::tryDecodeHash((string) $data['role_id']);
        abort_if($roleId === null, 422, 'Invalid role_id.');
        return [
            'name'         => $data['name'],
            'email'        => $data['email'],
            'role_id'      => $roleId,
            'send_welcome' => (bool) ($data['send_welcome'] ?? true),
        ];
    }
}
