<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Common\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password'           => ['required', 'string'],
            'new_password'               => ['required', 'string', 'confirmed', new StrongPassword()],
            'new_password_confirmation'  => ['required', 'string'],
        ];
    }
}
