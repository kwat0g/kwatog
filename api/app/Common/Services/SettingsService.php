<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Application-wide key/value settings backed by the `settings` table.
 * Cached in Redis (1 hour TTL); writes invalidate matching keys.
 */
class SettingsService
{
    private const CACHE_TTL = 3600; // 1 hour

    public function get(string $key, mixed $default = null): mixed
    {
        // The settings table doesn't exist until Task 12 migrates it.
        // Until then, return the default so middleware can boot cleanly.
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        return Cache::remember("settings:{$key}", self::CACHE_TTL, function () use ($key, $default) {
            $row = \DB::table('settings')->where('key', $key)->first();
            return $row ? json_decode($row->value, true) : $default;
        });
    }

    public function set(string $key, mixed $value, ?string $group = null): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        \DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value'      => json_encode($value),
                'group'      => $group ?? 'general',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget("settings:{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return \DB::table('settings')->where('group', $group)->get()
            ->mapWithKeys(fn ($row) => [$row->key => json_decode($row->value, true)])
            ->all();
    }

    public function flushCache(): void
    {
        // Coarse flush — fine for Sprint 1. Refine per-key in Sprint 8.
        Cache::flush();
    }
}
