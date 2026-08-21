<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // Never cache a missing setting's fallback. Migrations insert settings
        // directly, so caching []/null before a migration or seeder runs can
        // leave a valid setting invisible until the Redis TTL expires.
        // Cache::get can throw when the configured driver is unavailable;
        // fall back to a direct DB read so callers never crash on a transient
        // cache outage.
        try {
            $cacheMiss = new \stdClass();
            $cached = Cache::get("settings:{$key}", $cacheMiss);
            if ($cached !== $cacheMiss) {
                return $cached;
            }

            $row = DB::table('settings')->where('key', $key)->first();
            if (! $row) {
                return $default;
            }

            $value = json_decode($row->value, true) ?? $default;
            Cache::put("settings:{$key}", $value, self::CACHE_TTL);
            return $value;
        } catch (\Throwable $e) {
            return $this->fetch($key, $default);
        }
    }

    public function requiredString(string $key, bool $allowEmpty = false): string
    {
        $value = $this->get($key);
        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is missing or invalid.");
        }
        return $value;
    }

    public function requiredInt(string $key, ?int $minimum = null, ?int $maximum = null): int
    {
        $value = $this->get($key);
        if (! is_numeric($value) || (int) $value != (float) $value) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is missing or invalid.");
        }
        $value = (int) $value;
        if (($minimum !== null && $value < $minimum) || ($maximum !== null && $value > $maximum)) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is outside its valid range.");
        }
        return $value;
    }

    public function requiredBool(string $key): bool
    {
        $value = $this->get($key);
        if (! is_bool($value)) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is missing or invalid.");
        }
        return $value;
    }

    public function requiredFloat(string $key, ?float $minimum = null, ?float $maximum = null): float
    {
        $value = $this->get($key);
        if (! is_numeric($value)) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is missing or invalid.");
        }
        $value = (float) $value;
        if (($minimum !== null && $value < $minimum) || ($maximum !== null && $value > $maximum)) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required setting {$key} is outside its valid range.");
        }
        return $value;
    }

    private function fetch(string $key, mixed $default): mixed
    {
        $row = DB::table('settings')->where('key', $key)->first();
        $val = $row ? json_decode($row->value, true) : null;
        return $val ?? $default;
    }

    public function set(string $key, mixed $value, ?string $group = null, ?string $label = null, ?string $description = null): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $existing = DB::table('settings')->where('key', $key)->first();
        $payload = [
            'value' => json_encode($value),
            'updated_at' => now(),
        ];
        if ($group !== null) {
            $payload['group'] = $group;
        }
        if ($label !== null) {
            $payload['label'] = $label;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }

        if ($existing) {
            DB::table('settings')->where('key', $key)->update($payload);
        } else {
            DB::table('settings')->insert([
                ...$payload,
                'key' => $key,
                'group' => $group ?? 'general',
                'created_at' => now(),
            ]);
        }

        try {
            Cache::forget("settings:{$key}");
        } catch (\Throwable $e) {
            // Cache layer may be unavailable; the next read will go straight
            // to the DB so this is non-fatal.
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return DB::table('settings')->where('group', $group)->get()
            ->mapWithKeys(fn ($row) => [$row->key => json_decode($row->value, true)])
            ->all();
    }

    public function flushCache(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // Clear only settings entries. Cache::flush() would also delete
        // sessions, queued jobs, rate-limit counters, and unrelated caches.
        try {
            DB::table('settings')->pluck('key')->each(
                static fn (string $key): bool => Cache::forget("settings:{$key}"),
            );
        } catch (\Throwable $e) {
            // Cache invalidation is best effort; reads fall back to the DB.
        }
    }
}
