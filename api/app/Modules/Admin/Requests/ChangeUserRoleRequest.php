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
        return ['role_id' => ['required', 'string']];
    }

    public function decodedRoleId(): int
    {
        $id = Role::tryDecodeHash((string) $this->validated('role_id'));
        abort_if($id === null, 422, 'Invalid role_id.');
        return $id;
    }
}
