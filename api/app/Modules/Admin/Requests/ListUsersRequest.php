<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'search'        => ['nullable', 'string', 'max:120'],
            'role_id'       => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'status'        => ['nullable', Rule::in(['active', 'inactive', 'locked'])],
            'sort'          => ['nullable', Rule::in(['name', 'email', 'last_activity', 'created_at'])],
            'direction'     => ['nullable', Rule::in(['asc', 'desc'])],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
