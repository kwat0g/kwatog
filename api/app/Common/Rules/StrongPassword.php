<?php

declare(strict_types=1);

namespace App\Common\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password policy reads its minimum length from persisted security settings.
 */
class StrongPassword implements ValidationRule
{
    public const REQUIRE_UPPERCASE = true;
    public const REQUIRE_LOWERCASE = true;
    public const REQUIRE_DIGIT = true;
    public const REQUIRE_SPECIAL = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $minimum = app(\App\Common\Services\SettingsService::class)->requiredInt('security.password_min_length', 1);
        if (! is_string($value) || mb_strlen($value) < $minimum) {
            $fail("The :attribute must be at least {$minimum} characters.");
            return;
        }
        if (self::REQUIRE_UPPERCASE && ! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must include at least one uppercase letter.');
            return;
        }
        if (self::REQUIRE_LOWERCASE && ! preg_match('/[a-z]/', $value)) {
            $fail('The :attribute must include at least one lowercase letter.');
            return;
        }
        if (self::REQUIRE_DIGIT && ! preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must include at least one digit.');
            return;
        }
        if (self::REQUIRE_SPECIAL && ! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must include at least one special character.');
        }
    }
}
