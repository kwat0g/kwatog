<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $userId = is_object($user) && method_exists($user, 'getKey') ? $user->getKey() : $user;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }

    /** @return array{name: string, email: string} */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'email' => strtolower(trim((string) $this->validated('email'))),
        ];
    }
}
