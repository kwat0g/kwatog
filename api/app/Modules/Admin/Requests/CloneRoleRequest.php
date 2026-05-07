<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Series R — Task R1.
 *
 * Validates the body of POST /admin/roles/{role}/clone.
 * The source role is bound from the route; this request only validates the
 * new role's name + slug (must be unique against existing roles).
 */
class CloneRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:50'],
            'slug'        => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('roles', 'slug')],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique'    => 'A role with this slug already exists.',
            'slug.alpha_dash' => 'Slug may only contain letters, numbers, dashes, and underscores.',
        ];
    }
}
