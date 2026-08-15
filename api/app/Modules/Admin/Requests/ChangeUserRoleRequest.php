<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'role_id'          => ['required', 'string'],
            'expected_role_id' => ['required', 'string'],
            'reason'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function decodedRoleId(): int
    {
        $id = Role::tryDecodeHash((string) $this->validated('role_id'));
        abort_if($id === null, 422, 'Invalid role_id.');
        return $id;
    }

    public function decodedExpectedRoleId(): int
    {
        $id = Role::tryDecodeHash((string) $this->validated('expected_role_id'));
        abort_if($id === null, 422, 'Invalid expected_role_id.');
        return $id;
    }

    public function reason(): string
    {
        return trim((string) ($this->validated('reason') ?? '')) ?: 'Admin role assignment';
    }
}
