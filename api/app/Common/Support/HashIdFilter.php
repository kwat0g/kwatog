<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Decode a hash-or-numeric filter value to an integer ID.
 * Frontend usually sends HashIDs (strings); tinker / tests send raw ints.
 * Returns null when the value is empty or cannot be decoded — callers should
 * skip the WHERE clause in that case rather than match against NULL.
 */
class HashIdFilter
{
    public static function decode(mixed $value, string $modelClass): ?int
    {
        if ($value === null || $value === '' || $value === '0') return null;
        $str = (string) $value;
        if (ctype_digit($str)) {
            return (int) $str;
        }
        // Models that use HasHashId expose tryDecodeHash().
        if (method_exists($modelClass, 'tryDecodeHash')) {
            return $modelClass::tryDecodeHash($str);
        }
        return null;
    }
}
