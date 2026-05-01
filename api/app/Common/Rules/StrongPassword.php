<?php

declare(strict_types=1);

namespace App\Common\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password policy: min 8 + at least one uppercase + one digit + one special.
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 8) {
            $fail('The :attribute must be at least 8 characters.');
            return;
        }
        if (! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must include at least one uppercase letter.');
            return;
        }
        if (! preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must include at least one digit.');
            return;
        }
        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must include at least one special character.');
        }
    }
}
