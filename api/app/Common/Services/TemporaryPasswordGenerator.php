<?php

declare(strict_types=1);

namespace App\Common\Services;

/**
 * Creates temporary credentials that already satisfy the password policy.
 *
 * The fixed suffix guarantees every generated password contains uppercase,
 * lowercase, numeric, and special characters; the random prefix keeps the
 * credential unpredictable. The recipient must still replace it at first
 * login.
 */
class TemporaryPasswordGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16)).'!Aa1';
    }
}
