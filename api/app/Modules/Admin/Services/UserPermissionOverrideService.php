<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Events\PermissionOverrideChanged;
use App\Common\Models\AuditLog;
use App\Common\Services\NotificationService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private NotificationService $notifications) {}

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
        /** @var Permission $permission */
        $permission = Permission::where('slug', $permissionSlug)->firstOrFail();

        $existing = UserPermissionOverride::with(['permission', 'user'])
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->first();

        $override = DB::transaction(function () use ($user, $actor, $permission, $type, $reason, $expiresAt, $existing) {
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

            $override->load(['permission', 'grantedBy']);

            AuditLog::create([
                'user_id'    => $actor->id,
                'action'     => $existing ? 'updated' : 'created',
                'model_type' => $override->getMorphClass(),
                'model_id'   => $override->getKey(),
                'old_values' => $existing ? [
                    'permission_slug' => $existing->permission->slug,
                    'type'          => $existing->type->value,
                    'reason'        => $existing->reason,
                    'expires_at'    => $existing->expires_at?->toIso8601String(),
                    'target_user_id'  => $existing->user_id,
                    'target_user'     => $existing->user->name,
                ] : null,
                'new_values' => [
                    'permission_slug' => $override->permission->slug,
                    'type'          => $override->type->value,
                    'reason'        => $override->reason,
                    'expires_at'    => $override->expires_at?->toIso8601String(),
                    'target_user_id'  => $user->id,
                    'target_user'     => $user->name,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            $user->flushPermissionsCache();

            return $override;
        });

        DB::afterCommit(function () use ($user, $permissionSlug, $existing, $type, $reason) {
            event(new PermissionOverrideChanged(
                $user->id,
                $permissionSlug,
                $existing ? $existing->type : null,
                $type,
                $reason,
            ));

            $this->notifications->send($user, 'permission.override', [
                'title' => $existing ? 'Permission Override Updated' : 'Permission Override Applied',
                'message' => $existing
                    ? "Your override for \"{$permissionSlug}\" has been changed from \"{$existing->type->value}\" to \"{$type->value}\"."
                    : "A new permission override \"{$type->value}\" has been applied for \"{$permissionSlug}\".",
                'link_to' => "/admin/users/{$user->hash_id}",
            ]);
        });

        return $override;
    }

    public function remove(UserPermissionOverride $override): void
    {
        $override->load(['user', 'permission']);
        $userId = $override->user_id;
        $permissionSlug = $override->permission->slug;
        $oldType = $override->type;
        $reason = $override->reason;
        $user = $override->user;

        DB::transaction(function () use ($override, $user) {
            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'deleted',
                'model_type' => $override->getMorphClass(),
                'model_id'   => $override->getKey(),
                'old_values' => [
                    'permission_slug' => $override->permission->slug,
                    'type'          => $override->type->value,
                    'reason'        => $override->reason,
                    'expires_at'    => $override->expires_at?->toIso8601String(),
                    'target_user_id'  => $override->user_id,
                    'target_user'     => $override->user->name,
                ],
                'new_values' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            $override->delete();

            if ($user) {
                $user->flushPermissionsCache();
            }
        });

        DB::afterCommit(function () use ($userId, $permissionSlug, $oldType, $reason, $user) {
            if ($userId) {
                event(new PermissionOverrideChanged(
                    $userId,
                    $permissionSlug,
                    $oldType,
                    null,
                    $reason,
                ));
            }

            if ($user) {
                $this->notifications->send($user, 'permission.override', [
                    'title' => 'Permission Override Removed',
                    'message' => "Your override for \"{$permissionSlug}\" has been removed.",
                    'link_to' => "/admin/users/{$user->hash_id}",
                ]);
            }
        });
    }
}
