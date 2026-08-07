<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
 * legacy `edge.system_user.*` by migration 0450).
 */
class SystemUserResolver
{
    public const CACHE_KEY         = 'system:user_id';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Resolve (or lazily provision) the system user id. Cached forever
     * once seeded; re-provisioned if the cached id is stale.
     */
    public function id(): int
    {
        $email = $this->settings->requiredString('system_user.email');
        $name = $this->settings->requiredString('system_user.name');
        $cached = Cache::get(self::CACHE_KEY);
        if (is_int($cached) && User::query()->whereKey($cached)->exists()) {
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
        Cache::forever(self::CACHE_KEY, $user->id);
        return $user->id;
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
