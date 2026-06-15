<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * T2.2 / T2.3 / T2.4 shared. Resolves the audit-trail user that every Edge
 * ingest persists under (`recorded_by` on MachineConditionReading /
 * WorkOrderOutput, `created_by` on the auto-generated MWO, etc.).
 *
 * Why this class exists: under `auth:edge_device`, `Auth::id()` returns the
 * EdgeDevice PK. Models that write audit rows (HasAuditLog) would then
 * violate `audit_logs.user_id → users(id)` FK. We pin the auth context to a
 * real `users` row for the duration of the call.
 */
class EdgeSystemUserResolver
{
    public const SYSTEM_USER_EMAIL = 'edge-system@ogami.internal';
    public const CACHE_KEY         = 'edge:system_user_id';

    /**
     * Resolve (or lazily provision) the edge-system user id. Cached forever
     * once seeded; re-provisioned if the cached id is stale.
     */
    public function id(): int
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_int($cached) && User::query()->whereKey($cached)->exists()) {
            return $cached;
        }

        $user = User::query()->where('email', self::SYSTEM_USER_EMAIL)->first();
        if (! $user) {
            $roleId = Role::query()->where('slug', 'employee')->value('id')
                ?? Role::query()->orderBy('id')->value('id');
            $user = User::create([
                'name'                => 'Edge System',
                'email'               => self::SYSTEM_USER_EMAIL,
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
     * Run $fn impersonating the edge-system user on the `web` guard so
     * HasAuditLog's Auth::id() returns a valid users.id rather than the
     * EdgeDevice PK from the `edge_device` guard.
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
