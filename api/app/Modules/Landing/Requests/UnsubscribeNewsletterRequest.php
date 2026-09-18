<?php

declare(strict_types=1);

namespace App\Modules\Landing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnsubscribeNewsletterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No `consent` here — unsubscribing must never require re-consenting.
            'email' => ['required', 'string', 'email', 'max:150'],
        ];
    }
}
