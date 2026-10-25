<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * WS-A.1 — Public endpoint validation for accepting a portal invite.
 *
 * No authentication required — the invite token is the credential. We
 * still require a strong password to prevent provisioning weak accounts.
 */
class AcceptUserInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public.
        return true;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'name'     => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
