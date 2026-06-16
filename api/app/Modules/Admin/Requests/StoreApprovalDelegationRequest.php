<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OGAMI-013 — validates creation of an approval delegation.
 *
 * A user nominates someone to cover their approval authority for a window.
 * `role_slug` is OPTIONAL — null means "every role I currently hold".
 */
class StoreApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may set up cover for themselves. The service
        // pins delegator_user_id to the acting user (or an explicit target only
        // when the actor is system_admin), so this cannot be abused to delegate
        // someone else's authority.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'delegate_user_id'  => ['required', 'string'], // hash_id
            'delegator_user_id' => ['nullable', 'string'], // hash_id; admin-only
            'role_slug'         => ['nullable', 'string', 'exists:roles,slug'],
            'starts_at'         => ['required', 'date'],
            'ends_at'           => ['required', 'date', 'after_or_equal:starts_at'],
            'reason'            => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after_or_equal' => 'The end date must be on or after the start date.',
            'role_slug.exists'       => 'The selected role does not exist.',
        ];
    }
}
