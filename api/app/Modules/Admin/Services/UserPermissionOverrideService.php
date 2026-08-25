<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Events\PermissionOverrideChanged;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of `user_permission_overrides`.
 *
 * Each mutation is serialized on the target user's row, audited exactly once,
 * and published through the outbox in the same transaction as the write.
 * Trashed rows are restored in place so the permanent (user, permission)
 * unique key remains the single source of truth.
 */
class UserPermissionOverrideService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RbacAuditService $audit,
    ) {}

    /**
     * Active (non-expired) overrides for a user, eager-loaded for resource.
     *
     * @return Collection<int, UserPermissionOverride>
     */
    public function listActive(User $user, User $actor, bool $includeDeleted = false): Collection
    {
        $this->assertSystemAdmin($actor);

        if ($includeDeleted) {
            $query = UserPermissionOverride::withTrashed()
                ->with(['permission', 'grantedBy'])
                ->where('user_id', $user->id)
                ->where(function ($query): void {
                    $query
                        ->whereNotNull('deleted_at')
                        ->orWhere(fn ($active) => $active
                            ->whereNull('deleted_at')
                            ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        );
                })
                ->orderBy('created_at', 'desc');
        } else {
            $query = UserPermissionOverride::query()
                ->with(['permission', 'grantedBy'])
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('created_at', 'desc');
        }

        return $query->get();
    }

    public function set(
        User $user,
        User $actor,
        string $permissionSlug,
        PermissionOverrideType $type,
        string $reason,
        ?Carbon $expiresAt = null,
    ): UserPermissionOverride {
        $this->assertSystemAdmin($actor);

        /** @var Permission $permission */
        $permission = Permission::where('slug', $permissionSlug)->firstOrFail();
        $wasExisting = false;
        $wasTrashed = false;
        $oldType = null;

        $override = DB::transaction(function () use (
            $user,
            $actor,
            $permission,
            $permissionSlug,
            $type,
            $reason,
            $expiresAt,
            &$wasExisting,
            &$wasTrashed,
            &$oldType,
        ): UserPermissionOverride {
            // A missing child row cannot be locked. Serialize on the stable
            // parent so two first-time sets for the same user cannot both
            // classify themselves as creates or publish conflicting state.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = UserPermissionOverride::withTrashed()
                ->with(['permission', 'user'])
                ->where('user_id', $lockedUser->id)
                ->where('permission_id', $permission->id)
                ->lockForUpdate()
                ->first();

            $wasExisting = $existing !== null;
            $wasTrashed = $existing?->trashed() ?? false;
            $oldType = $existing?->type;
            $oldValues = $existing ? $this->snapshot($existing) : null;

            if ($existing) {
                // Restore the same row before updating it. This preserves the
                // existing unique key and keeps remove → regrant deterministic.
                $existing->setAttribute('deleted_at', null);
                $existing->fill([
                    'type' => $type,
                    'granted_by' => $actor->id,
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                ]);
                $existing->save();
                $override = $existing;
            } else {
                $override = UserPermissionOverride::create([
                    'user_id' => $lockedUser->id,
                    'permission_id' => $permission->id,
                    'type' => $type,
                    'granted_by' => $actor->id,
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                ]);
            }

            $override->load(['permission', 'grantedBy', 'user']);
            $action = ! $wasExisting ? 'created' : ($wasTrashed ? 'restored' : 'updated');
            $this->audit->record(
                $override,
                $action,
                $oldValues,
                $this->snapshot($override),
                $actor,
                $reason,
            );

            $lockedUser->flushPermissionsCache();

            app(OutboxService::class)->record(new PermissionOverrideChanged(
                $lockedUser->id,
                $permissionSlug,
                $oldType,
                $type,
                $reason,
            ));

            return $override;
        });

        DB::afterCommit(function () use ($user, $permissionSlug, $oldType, $type, $wasExisting, $wasTrashed): void {
            $title = ! $wasExisting
                ? 'Permission Override Applied'
                : ($wasTrashed ? 'Permission Override Restored' : 'Permission Override Updated');
            $message = $wasExisting && $oldType !== null
                ? "Your override for \"{$permissionSlug}\" has been changed from \"{$oldType->value}\" to \"{$type->value}\"."
                : "A new permission override \"{$type->value}\" has been applied for \"{$permissionSlug}\".";

            $this->notifications->send($user, 'permission.override', [
                'title' => $title,
                'message' => $message,
                'link_to' => "/admin/users/{$user->hash_id}",
            ]);
        });

        return $override;
    }

    public function remove(UserPermissionOverride $override, ?User $actor = null): void
    {
        $this->assertSystemAdmin($actor);

        /** @var array{user: User, permissionSlug: string, oldType: PermissionOverrideType, reason: string}|null $removed */
        $removed = DB::transaction(function () use ($override, $actor): ?array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($override->user_id);
            $locked = UserPermissionOverride::withTrashed()
                ->with(['permission', 'user'])
                ->lockForUpdate()
                ->findOrFail($override->id);

            // A repeated remove is an idempotent no-op. The command and HTTP
            // paths therefore cannot create duplicate delete audit rows.
            if ($locked->trashed()) {
                return null;
            }

            $oldValues = $this->snapshot($locked);
            $permissionSlug = $locked->permission->slug;
            $oldType = $locked->type;
            $reason = $locked->reason;

            $locked->delete();
            $this->audit->record($locked, 'deleted', $oldValues, null, $actor);
            $lockedUser->flushPermissionsCache();

            app(OutboxService::class)->record(new PermissionOverrideChanged(
                $lockedUser->id,
                $permissionSlug,
                $oldType,
                null,
                $reason,
            ));

            return [
                'user' => $lockedUser,
                'permissionSlug' => $permissionSlug,
                'oldType' => $oldType,
                'reason' => $reason,
            ];
        });

        if ($removed === null) {
            return;
        }

        DB::afterCommit(function () use ($removed): void {
            $this->notifications->send($removed['user'], 'permission.override', [
                'title' => 'Permission Override Removed',
                'message' => "Your override for \"{$removed['permissionSlug']}\" has been removed.",
                'link_to' => "/admin/users/{$removed['user']->hash_id}",
            ]);
        });
    }

    public function restore(User $user, UserPermissionOverride $override, User $actor): void
    {
        $this->assertSystemAdmin($actor);

        /** @var array{permissionSlug: string, newType: PermissionOverrideType, reason: string}|null $restored */
        $restored = DB::transaction(function () use ($user, $override, $actor): ?array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked = UserPermissionOverride::withTrashed()
                ->with(['permission', 'user'])
                ->lockForUpdate()
                ->findOrFail($override->id);

            abort_unless($locked->user_id === $lockedUser->id, 404);
            if (! $locked->trashed()) {
                return null;
            }

            $oldValues = $this->snapshot($locked);
            $permissionSlug = $locked->permission->slug;
            $newType = $locked->type;
            $reason = $locked->reason;

            $locked->setAttribute('deleted_at', null);
            $locked->save();
            $locked->load(['permission', 'grantedBy', 'user']);
            $this->audit->record(
                $locked,
                'restored',
                $oldValues,
                $this->snapshot($locked),
                $actor,
                $reason,
            );
            $lockedUser->flushPermissionsCache();

            app(OutboxService::class)->record(new PermissionOverrideChanged(
                $lockedUser->id,
                $permissionSlug,
                null,
                $newType,
                $reason,
            ));

            return [
                'permissionSlug' => $permissionSlug,
                'newType' => $newType,
                'reason' => $reason,
            ];
        });

        if ($restored === null) {
            return;
        }

        DB::afterCommit(function () use ($user, $restored): void {
            $this->notifications->send($user, 'permission.override', [
                'title' => 'Permission Override Restored',
                'message' => "Your \"{$restored['newType']->value}\" override for \"{$restored['permissionSlug']}\" has been restored.",
                'link_to' => "/admin/users/{$user->hash_id}",
            ]);
        });
    }

    private function assertSystemAdmin(?User $actor): void
    {
        // A null actor is reserved for the audited scheduler/console path.
        // Every HTTP caller passes its authenticated user and is checked here
        // in addition to route middleware and the store FormRequest.
        if ($actor === null) {
            return;
        }

        abort_unless(
            $actor->role?->slug === 'system_admin',
            403,
            'Only system administrators may manage permission overrides.',
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(UserPermissionOverride $override): array
    {
        $type = $override->type instanceof PermissionOverrideType
            ? $override->type->value
            : (string) $override->type;

        return [
            'permission_slug' => $override->permission?->slug,
            'type' => $type,
            'reason' => $override->reason,
            'expires_at' => $override->expires_at?->toIso8601String(),
            'target_user_id' => $override->user_id,
            'target_user' => $override->user?->name,
        ];
    }
}
