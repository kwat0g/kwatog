<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared service-account resolver.
 *
 * Resolves (or lazily provisions) the system user that owns audit rows
 * written under non-web guards (`auth:supplier_portal`,
 * `auth:customer_portal`). Under those guards `Auth::id()` returns a
 * non-User PK, which would violate the `audit_logs.user_id → users(id)` FK;
 * `impersonate()` pins the auth context to a real `users` row for the call.
 *
 * Settings keys: `system_user.email` / `system_user.name` (renamed from the
 * legacy service-account keys by migration 0450).
 */
class SystemUserResolver
{
    public const CACHE_KEY         = 'system:user_id';
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Resolve (or lazily provision) the system user id. The cache is scoped
     * to the configured email and expires daily; a settings change cannot
     * keep routing audit writes to a previous service account.
     */
    public function id(): int
    {
        $email = strtolower(trim($this->settings->requiredString('system_user.email')));
        $name = $this->settings->requiredString('system_user.name');
        $cacheKey = self::CACHE_KEY.':'.hash('sha256', $email);
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable $exception) {
            Log::warning('System user cache read failed; resolving the configured account from the source table.', [
                'key_hash' => hash('sha256', $cacheKey),
                'exception' => $exception::class,
            ]);
            $cached = null;
        }
        if (is_int($cached) && User::query()->whereKey($cached)->where('email', $email)->exists()) {
            return $cached;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $defaultRoleSlug = $this->settings->requiredString('hr.default_user_role_slug');
            $roleId = Role::query()->where('slug', $defaultRoleSlug)->value('id')
                ?? Role::query()->orderBy('id')->value('id');
            $user = User::create([
                'name'                => $name,
                'email'               => $email,
                'password'            => bcrypt(Str::random(40)),
                'role_id'             => $roleId,
                'is_active'           => true,
                'password_changed_at' => now(),
            ]);
        }
        $userId = (int) $user->id;
        DB::afterCommit(static function () use ($cacheKey, $userId): void {
            try {
                Cache::put($cacheKey, $userId, self::CACHE_TTL_SECONDS);
                // Remove the pre-scope key after durable settings/user state is visible.
                Cache::forget(self::CACHE_KEY);
            } catch (Throwable $exception) {
                Log::warning('System user cache refresh failed after commit.', [
                    'exception' => $exception::class,
                ]);
            }
        });

        return $userId;
    }

    /**
     * Run $fn impersonating the system user on the `web` guard so
     * HasAuditLog's Auth::id() returns a valid users.id rather than the
     * portal-user PK from a non-web guard.
     */
    public function impersonate(callable $fn): mixed
    {
        $id = $this->id();
        $previous = Auth::getDefaultDriver();
        Auth::shouldUse('web');
        Auth::guard('web')->onceUsingId($id);
        try {
            return $fn();
        } finally {
            Auth::shouldUse($previous);
        }
    }

    public function user(): User
    {
        return User::query()->whereKey($this->id())->firstOrFail();
    }
}
