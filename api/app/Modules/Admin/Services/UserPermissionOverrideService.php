<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series R — Task R2.
 *
 * Owns the lifecycle of `user_permission_overrides`. Each mutation:
 *   1. Upserts the row by (user_id, permission_id) — never duplicates.
 *   2. Wraps writes in DB::transaction for consistency with audit_logs.
 *   3. Busts the affected user's permission cache so the change takes
 *      effect on the next request without waiting for the 5-min TTL.
 *
 * The runtime resolver lives in User::getPermissionSlugsAttribute, which
 * reads ALL non-expired overrides and applies grants/revokes after role
 * permissions.
 */
class UserPermissionOverrideService
{
    /**
     * Active (non-expired) overrides for a user, eager-loaded for resource.
     *
     * @return Collection<int, UserPermissionOverride>
     */
    public function listActive(User $user): Collection
    {
        return UserPermissionOverride::query()
            ->with(['permission', 'grantedBy'])
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function set(User $user, User $actor, string $permissionSlug, PermissionOverrideType $type, string $reason, ?Carbon $expiresAt = null): UserPermissionOverride
    {
        return DB::transaction(function () use ($user, $actor, $permissionSlug, $type, $reason, $expiresAt) {
            /** @var Permission $permission */
            $permission = Permission::where('slug', $permissionSlug)->firstOrFail();

            $override = UserPermissionOverride::updateOrCreate(
                [
                    'user_id'       => $user->id,
                    'permission_id' => $permission->id,
                ],
                [
                    'type'       => $type,
                    'granted_by' => $actor->id,
                    'reason'     => $reason,
                    'expires_at' => $expiresAt,
                ],
            );

            $user->flushPermissionsCache();

            return $override->load(['permission', 'grantedBy']);
        });
    }

    public function remove(UserPermissionOverride $override): void
    {
        DB::transaction(function () use ($override) {
            $user = $override->user; // eager load not required; small lookup
            $override->delete();

            if ($user) {
                $user->flushPermissionsCache();
            }
        });
    }
}
