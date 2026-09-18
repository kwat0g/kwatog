<?php

declare(strict_types=1);

namespace App\Modules\HR\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypt an array payload at rest while keeping the underlying column a valid
 * JSON value.
 *
 * `profile_update_requests.changes` is a `json` column, so Laravel's built-in
 * `encrypted:array` cast cannot be used: it binds a bare ciphertext string,
 * which Postgres refuses to parse as JSON. This cast wraps the ciphertext in a
 * JSON string (`"eyJpdiI6..."`), which is valid JSON, and unwraps it on read.
 *
 * Rows written before this cast existed are plaintext JSON arrays; `get()`
 * detects that shape and returns it unchanged, so legacy records stay readable.
 * A value that looks encrypted but fails to decrypt fails closed to null rather
 * than leaking or throwing.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
class EncryptedArrayCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        // Legacy plaintext row: a JSON object/array, not a JSON string.
        if (is_array($decoded)) {
            return $decoded;
        }

        if (! is_string($decoded)) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($decoded);
        } catch (DecryptException) {
            return null; // tampered or key-rotated value — do not expose it.
        }

        $array = json_decode($plain, true);

        return is_array($array) ? $array : null;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_THROW_ON_ERROR);

        // Double-encode: the outer JSON is the column value, the inner string
        // is the ciphertext.
        return json_encode(Crypt::encryptString($json), JSON_THROW_ON_ERROR);
    }
}
