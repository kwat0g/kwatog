<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.roles.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('role')?->id;
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:50'],
            'slug'        => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('roles', 'slug')->ignore($id)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
