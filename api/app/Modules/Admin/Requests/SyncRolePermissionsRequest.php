<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'permission_slugs'   => ['required', 'array'],
            'permission_slugs.*' => ['string', 'exists:permissions,slug'],
        ];
    }
}
