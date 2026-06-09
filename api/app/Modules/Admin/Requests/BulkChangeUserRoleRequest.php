<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class BulkChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'string', 'distinct'],
            'role_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

/**
      * @return array{ids: int[], invalid: string[]} Decoded user IDs and list of invalid hashes
      */
    public function decodedUserIds(): array
    {
        $ids = [];
        $invalid = [];

        foreach ((array) $this->validated('user_ids') as $hash) {
            $id = User::tryDecodeHash((string) $hash);
            if ($id !== null) {
                $ids[] = $id;
            } else {
                $invalid[] = (string) $hash;
            }
        }

        if (empty($invalid) && empty($ids)) {
            abort(422, 'No valid user IDs provided.');
        }

        return ['ids' => $ids, 'invalid' => $invalid];
    }

    public function decodedRoleId(): int
    {
        $id = Role::tryDecodeHash((string) $this->validated('role_id'));
        abort_if($id === null, 422, 'Invalid role_id.');
        return $id;
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}