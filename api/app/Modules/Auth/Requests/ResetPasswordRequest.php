<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Common\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'confirmed', new StrongPassword()],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
