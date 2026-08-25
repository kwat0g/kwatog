<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
     * Update an existing setting from an authenticated admin request.
     *
     * Seeder/bootstrap code may still use set(), which can create new keys.
     * The admin path is deliberately narrower: it locks an existing row,
     * preserves the stored JSON type, and emits an immutable audit record with
     * sensitive setting values redacted.
     */
    public function updateFromAdmin(string $key, mixed $value, User $actor, ?string $reason = null): void
    {
        if (! Schema::hasTable('settings')) {
            throw ValidationException::withMessages([
                'key' => ['Settings are not available yet.'],
            ]);
        }

        $setting = DB::table('settings')
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if (! $setting) {
            throw ValidationException::withMessages([
                'key' => ['Unknown setting key.'],
            ]);
        }

        try {
            $oldValue = json_decode((string) $setting->value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException("Setting {$key} contains invalid JSON.", 0, $exception);
        }

        if (! self::valueKeepsStoredJsonType($oldValue, $value)) {
            throw ValidationException::withMessages([
                'value' => ['The new value must keep the setting’s existing JSON type.'],
            ]);
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ValidationException::withMessages([
                'value' => ['The value could not be encoded as JSON.'],
            ]);
        }

        $now = now();
        DB::table('settings')->where('id', $setting->id)->update([
            'value' => $encoded,
            'updated_by' => $actor->id,
            'updated_at' => $now,
        ]);

        $request = request();
        AuditLog::create([
            'user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'settings.updated',
            'model_type' => 'settings',
            'model_id' => $setting->id,
            'old_values' => [
                'key' => $key,
                'value' => self::redactSettingValue($key, $oldValue),
            ],
            'new_values' => [
                'key' => $key,
                'value' => self::redactSettingValue($key, $value),
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'source_command' => 'admin.settings.update',
            'correlation_id' => $request?->attributes->get('request_id')
                ?? $request?->header('X-Request-ID'),
            'reason' => mb_substr(trim($reason ?? '') ?: 'Admin settings update', 0, 2000),
            'created_at' => $now,
        ]);

        try {
            Cache::forget("settings:{$key}");
        } catch (\Throwable $exception) {
            // A cache outage must not roll back a committed settings change.
        }
    }

    /**
     * Whether a candidate admin value keeps the stored setting's JSON type.
     *
     * JSON has a single number type and PHP collapses whole floats on encode:
     * `json_encode(1.0)` emits `1`, which decodes back as an int. Splitting int
     * from float here therefore froze every whole-number decimal setting to
     * integers — `loans.company_loan.annual_interest_rate` stores `0` and
     * `loans.*.max_salary_multiplier` stores `1`, so `0.05` and `1.5` were
     * rejected as type changes and those rates became uneditable. Int-versus-
     * decimal is policed by the per-key `integer` rules in
     * UpdateSettingRequest, which is where a numeric contract belongs; this
     * guard only stops a value changing JSON *class* (number ↔ string ↔ array).
     */
    public static function valueKeepsStoredJsonType(mixed $stored, mixed $value): bool
    {
        return match (true) {
            is_bool($stored) => is_bool($value),
            is_int($stored), is_float($stored) => is_int($value) || is_float($value),
            is_string($stored) => is_string($value),
            is_array($stored) => is_array($value),
            $stored === null => $value === null,
            default => false,
        };
    }

    /**
     * Segment names whose value is a credential or a personal identifier.
     *
     * Matched against the key's LAST dot-segment only. A substring match here
     * redacted 69 of 420 seeded keys, because "accoun<b>tin</b>g",
     * "forecas<b>tin</b>g", "budge<b>tin</b>g" and "ra<b>tin</b>g" all contain
     * `tin`, and `bank`/`routing` matched GL account codes — so every
     * accounting settings change was audited as `***`. Worse, `password`
     * matched `security.password_min_length`, hiding exactly the security
     * policy history this audit trail exists to preserve.
     */
    private const SENSITIVE_SEGMENTS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'credential', 'credentials',
        'api_key', 'apikey', 'private_key', 'privatekey', 'access_key', 'secret_key',
        'tin', 'ssn', 'sss_no', 'philhealth_no', 'pagibig_no', 'iban', 'swift', 'swift_code',
        'bank_account', 'bank_account_no', 'account_no', 'routing_number', 'national_id', 'tax_id',
    ];

    /** Compound suffixes such as `smtp_password` or `webhook_secret`. */
    private const SENSITIVE_SUFFIXES = [
        '_password', '_passwd', '_passphrase', '_secret', '_token', '_credential',
        '_credentials', '_api_key', '_apikey', '_private_key', '_access_key', '_secret_key',
    ];

    private static function redactSettingValue(string $key, mixed $value): mixed
    {
        $segments = explode('.', strtolower($key));
        $last = (string) end($segments);

        if (in_array($last, self::SENSITIVE_SEGMENTS, true)) {
            return '***';
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($last, $suffix)) {
                return '***';
            }
        }

        return $value;
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
            DB::table('settings')->pluck('key')->each(static function (string $key): void {
                // Do not return Cache::forget()'s boolean: Collection::each()
                // treats false as a signal to stop iterating.
                Cache::forget("settings:{$key}");
            });
        } catch (\Throwable $e) {
            // Cache invalidation is best effort; reads fall back to the DB.
        }
    }
}
