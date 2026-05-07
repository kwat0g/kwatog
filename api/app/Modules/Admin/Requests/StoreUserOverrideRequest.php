<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use App\Common\Enums\PermissionOverrideType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Series R — Task R2.
 *
 * Validates the body of POST /admin/users/{user}/overrides.
 *
 * `reason` is REQUIRED (min 5 chars) so the audit log captures justification.
 * `expires_at` is OPTIONAL — null means the override never expires.
 */
class StoreUserOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.users.manage_permissions') ?? false;
    }

    public function rules(): array
    {
        return [
            'permission_slug' => ['required', 'string', 'exists:permissions,slug'],
            'type'            => ['required', Rule::in([
                PermissionOverrideType::Grant->value,
                PermissionOverrideType::Revoke->value,
            ])],
            'reason'          => ['required', 'string', 'min:5', 'max:500'],
            'expires_at'      => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_slug.exists' => 'The selected permission does not exist.',
            'reason.required'        => 'A reason is required so this override is auditable.',
            'reason.min'             => 'Please provide at least 5 characters explaining why this override is needed.',
            'expires_at.after'       => 'The expiry date must be in the future.',
        ];
    }
}
