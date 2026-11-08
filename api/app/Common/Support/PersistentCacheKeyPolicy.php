<?php

declare(strict_types=1);

namespace App\Common\Support;

/** Explicit exceptions for framework coordination keys that must outlive workers. */
final class PersistentCacheKeyPolicy
{
    /** @var array<string, list<string>> */
    private const KEYS = [
        'redis' => ['illuminate:queue:restart'],
    ];

    public static function isExpected(string $store, string $key): bool
    {
        return in_array($key, self::KEYS[$store] ?? [], true);
    }
}
