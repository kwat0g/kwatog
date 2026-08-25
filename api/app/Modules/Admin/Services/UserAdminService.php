<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\AuditLog;
use App\Common\Services\TemporaryPasswordGenerator;
use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Admin\Support\CreatedUser;
use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * U2 — central user-management service for the Admin > Users surface.
 */
class UserAdminService
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly TemporaryPasswordGenerator $temporaryPasswords,
        private readonly RbacAuditService $audit,
    ) {}

    /**
     * Role options are served from the user-management permission boundary.
     * User managers should not need role-edit permission merely to assign an
     * existing role. Delegated managers never receive system_admin as an
     * assignable option; the service enforces the same rule server-side.
     *
     * @return array{
     *   roles: array<int, array{id: string, name: string, slug: string, is_system: bool}>,
     *   departments: array<int, array{id: string, name: string}>
     * }
     */
    public function options(?User $actor = null): array
    {
        $actor = $this->resolveActor($actor);

        $roles = Role::query()
            ->when(
                $actor !== null && ! $this->isSystemAdmin($actor),
                fn ($query) => $query->where('slug', '!=', 'system_admin'),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_system'])
            ->map(fn (Role $role): array => [
                'id' => $role->hash_id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => (bool) $role->is_system,
            ])
            ->values()
            ->all();

        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'id' => $department->hash_id,
                'name' => $department->name,
            ])
            ->values()
            ->all();

        return [
            'roles' => $roles,
            'departments' => $departments,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['role', 'employee.department']);

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%");
            });
        }

        if (! empty($filters['role_id'])) {
            $query->where('role_id', $this->activeRoleId((string) $filters['role_id']));
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'active') {
                $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                    });
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === 'locked') {
                $query->where('is_active', true)
                    ->whereNotNull('locked_until')
                    ->where('locked_until', '>', now());
            }
        }

        if (! empty($filters['department_id'])) {
            $departmentId = $this->activeDepartmentId((string) $filters['department_id']);
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $departmentId));
        }

        $sort = $filters['sort'] ?? 'last_activity';
        $direction = $filters['direction'] ?? 'desc';
        $allowed = ['name', 'email', 'last_activity', 'created_at'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $direction);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function show(User $user): User
    {
        return $user->load(['role', 'employee.department', 'employee.position']);
    }

    public function createStandalone(array $data, ?User $actor = null): CreatedUser
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($data, $actor) {
            $role = $this->activeRole((int) $data['role_id'], lock: true);
            $this->assertCanAssignRole($actor, $role);
            $tempPassword = $data['temp_password'] ?? $this->temporaryPasswords->generate();

            /** @var User $user */
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'role_id' => $role->id,
                'employee_id' => null,
                'is_active' => true,
                'must_change_password' => true,
                'failed_login_attempts' => 0,
            ]);

            PasswordHistory::create([
                'user_id' => $user->id,
                'password_hash' => $user->password,
                'created_at' => now(),
            ]);

            $created = $user->fresh(['role']);
            $this->audit->record(
                $created,
                'created',
                null,
                $this->userSnapshot($created),
                $actor,
                'Admin user account created',
            );

            return new CreatedUser(
                user: $created,
                tempPassword: (string) $tempPassword,
            );
        });
    }

    public function unlock(User $user, ?User $actor = null): User
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->assertCanManageTarget($actor, $locked);
            $oldValues = $this->userSnapshot($locked);

            $locked->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $updated = $locked->fresh(['role']);
            $this->audit->record($updated, 'unlocked', $oldValues, $this->userSnapshot($updated), $actor, 'Admin account unlock');

            return $updated;
        });
    }

    public function deactivate(User $user, ?User $actor = null): User
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->assertCanManageTarget($actor, $locked);
            if ($actor?->id === $locked->id) {
                throw new ForbiddenActionException('You cannot deactivate your own account.');
            }
            if ($locked->is_active && $this->isSystemAdminUser($locked)) {
                $this->assertAtLeastOneActiveSystemAdminRemains();
            }

            $oldValues = $this->userSnapshot($locked);
            $locked->update(['is_active' => false]);
            if (method_exists($locked, 'tokens')) {
                $locked->tokens()->delete();
            }
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $locked->flushPermissionsCache();

            $updated = $locked->fresh(['role']);
            $this->audit->record($updated, 'deactivated', $oldValues, $this->userSnapshot($updated), $actor, 'Admin account deactivation');

            return $updated;
        });
    }

    public function activate(User $user, ?User $actor = null): User
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->assertCanManageTarget($actor, $locked);
            $oldValues = $this->userSnapshot($locked);
            $locked->update(['is_active' => true]);

            $updated = $locked->fresh(['role']);
            $this->audit->record($updated, 'activated', $oldValues, $this->userSnapshot($updated), $actor, 'Admin account activation');

            return $updated;
        });
    }

    public function changeRole(
        User $user,
        int $roleId,
        int $expectedRoleId,
        string $reason = '',
        ?User $actor = null,
    ): User {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $roleId, $expectedRoleId, $reason, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ((int) $locked->role_id !== $expectedRoleId) {
                throw new ConflictHttpException(
                    'This user role changed while you were editing. Refresh the user and retry with the current role.',
                );
            }

            $oldValues = $this->userSnapshot($locked);
            $oldRoleId = (int) $locked->role_id;
            if (! Role::query()->whereKey($expectedRoleId)->exists()) {
                throw new BusinessRuleException('The expected role is no longer available.');
            }
            $oldRole = Role::withTrashed()->find($oldRoleId);
            $newRole = $this->activeRole($roleId, lock: true);
            $this->assertCanManageTarget($actor, $locked);
            $this->assertCanAssignRole($actor, $newRole);
            if ($actor?->id === $locked->id && $oldRoleId !== $newRole->id) {
                throw new ForbiddenActionException('You cannot change your own role.');
            }
            if (
                $locked->is_active
                && $oldRole?->slug === 'system_admin'
                && $newRole->slug !== 'system_admin'
            ) {
                $this->assertAtLeastOneActiveSystemAdminRemains();
            }

            $locked->update(['role_id' => $newRole->id]);
            Cache::forget("auth:role_perms:{$oldRoleId}");
            $locked->flushPermissionsCache();

            $updated = $locked->fresh(['role']);
            $this->audit->record(
                $updated,
                'role_changed',
                $oldValues,
                [...$this->userSnapshot($updated), 'reason' => trim($reason) ?: 'Admin role assignment'],
                $actor,
                trim($reason) ?: 'Admin role assignment',
            );

            return $updated;
        });
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $expectedRoleIds  keyed by user ID
     * @return array{
     *   updated: int,
     *   conflicts: array<int, array{user_id: string, expected_role_id: ?string, actual_role_id: ?string}>,
     *   missing: array<int, string>
     * }
     */
    public function bulkChangeRole(
        array $userIds,
        int $roleId,
        string $reason = '',
        array $expectedRoleIds = [],
        ?User $actor = null,
    ): array {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($userIds, $roleId, $reason, $expectedRoleIds, $actor): array {
            $newRole = $this->activeRole($roleId, lock: true);
            $this->assertCanAssignRole($actor, $newRole);

            $users = User::query()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'name', 'role_id', 'is_active', 'employee_id', 'must_change_password', 'locked_until']);

            $oldRoleIds = $users->pluck('role_id', 'id')->all();
            $expectedRoleIdsToCheck = array_values(array_filter(
                $expectedRoleIds,
                static fn (mixed $id): bool => $id !== null,
            ));
            if (
                $expectedRoleIdsToCheck !== []
                && Role::query()->whereIn('id', $expectedRoleIdsToCheck)->count() !== count(array_unique($expectedRoleIdsToCheck))
            ) {
                throw new BusinessRuleException('One or more expected roles are no longer available.');
            }

            $conflicts = [];
            $writable = [];
            foreach ($users as $userRow) {
                $expected = $expectedRoleIds[$userRow->id] ?? null;
                if ($expected === null || (int) $userRow->role_id !== (int) $expected) {
                    $conflicts[] = [
                        'user_id' => $userRow->hash_id,
                        'expected_role_id' => $expected !== null ? $this->encodeId((int) $expected) : null,
                        'actual_role_id' => $userRow->role_id !== null ? $this->encodeId((int) $userRow->role_id) : null,
                    ];

                    continue;
                }
                $writable[] = $userRow->id;
            }

            $missing = collect($userIds)
                ->diff($users->pluck('id'))
                ->map(fn (int $id): string => $this->encodeId($id))
                ->values()
                ->all();

            if ($writable === []) {
                return ['updated' => 0, 'conflicts' => $conflicts, 'missing' => $missing];
            }

            foreach ($users as $userRow) {
                if (! in_array($userRow->id, $writable, true)) {
                    continue;
                }

                $target = $userRow->fresh(['role']);
                $this->assertCanManageTarget($actor, $target);
                if ($actor?->id === $target->id && (int) $target->role_id !== $newRole->id) {
                    throw new ForbiddenActionException('You cannot change your own role.');
                }
            }

            $leavingActiveSystemAdmins = $users->filter(
                fn (User $userRow): bool => in_array($userRow->id, $writable, true)
                    && (bool) $userRow->is_active
                    && $userRow->role?->slug === 'system_admin'
                    && $newRole->slug !== 'system_admin',
            )->count();
            if ($leavingActiveSystemAdmins > 0) {
                $this->assertAtLeastOneActiveSystemAdminRemains($leavingActiveSystemAdmins);
            }

            $changedOldRoleIds = array_intersect_key($oldRoleIds, array_flip($writable));
            User::query()
                ->whereIn('id', $writable)
                ->update(['role_id' => $newRole->id]);

            foreach ($users as $userRow) {
                if (in_array($userRow->id, $writable, true)) {
                    Cache::forget("auth:role_perms:{$userRow->role_id}");
                    $userRow->flushPermissionsCache();
                }
            }

            AuditLog::create([
                'user_id' => $actor?->id,
                'actor_type' => $actor instanceof User ? 'user' : 'system',
                'action' => 'bulk_role_change',
                'model_type' => 'App\\Modules\\Auth\\Models\\User',
                'model_id' => null,
                'old_values' => [
                    'user_ids' => $writable,
                    'old_role_ids' => $changedOldRoleIds,
                ],
                'new_values' => [
                    'user_ids' => $writable,
                    'new_role_id' => $newRole->id,
                    'new_role_slug' => $newRole->slug,
                    'reason' => $reason,
                    'conflicts' => $conflicts,
                    'missing' => $missing,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return ['updated' => count($writable), 'conflicts' => $conflicts, 'missing' => $missing];
        });
    }

    public function resetPassword(User $user, ?User $actor = null): string
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $actor): string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->assertCanManageTarget($actor, $locked);
            $oldValues = $this->userSnapshot($locked);
            $temporaryPassword = $this->provisioning->resetPasswordForUser($locked);
            $updated = $locked->fresh(['role']);

            $this->audit->record(
                $updated,
                'password_reset',
                $oldValues,
                $this->userSnapshot($updated),
                $actor,
                'Admin password reset',
            );

            return $temporaryPassword;
        });
    }

    public function updateProfile(User $user, array $data, ?User $actor = null): User
    {
        $actor = $this->resolveActor($actor);

        return DB::transaction(function () use ($user, $data, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->assertCanManageTarget($actor, $locked);
            if ($locked->employee_id !== null) {
                throw new BusinessRuleException(
                    'Linked employee account identity must be corrected from the employee profile.',
                );
            }

            $oldValues = $this->userSnapshot($locked);
            $locked->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);
            if ($locked->isDirty()) {
                $locked->save();
            }

            $updated = $locked->fresh(['role']);
            $this->audit->record($updated, 'profile_updated', $oldValues, $this->userSnapshot($updated), $actor, 'Admin profile correction');

            return $updated;
        });
    }

    public function loginHistory(User $user, int $limit = 50): Collection
    {
        return LoginHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function resolveActor(?User $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        $authenticated = Auth::user();

        return $authenticated instanceof User ? $authenticated : null;
    }

    private function activeRoleId(string $hash): int
    {
        $id = Role::tryDecodeHash($hash);
        if ($id === null || ! Role::query()->whereKey($id)->exists()) {
            throw new BusinessRuleException('The selected role is no longer available.');
        }

        return $id;
    }

    private function activeDepartmentId(string $hash): int
    {
        $id = Department::tryDecodeHash($hash);
        if ($id === null || ! Department::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('The selected department is no longer available.');
        }

        return $id;
    }

    private function activeRole(int $id, bool $lock = false): Role
    {
        $query = Role::query()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $role = $query->first();
        if (! $role) {
            throw new BusinessRuleException('The selected role is no longer available.');
        }

        return $role;
    }

    private function isSystemAdmin(User $actor): bool
    {
        return $actor->role?->slug === 'system_admin';
    }

    private function isSystemAdminUser(User $user): bool
    {
        return $user->role?->slug === 'system_admin';
    }

    private function assertCanAssignRole(?User $actor, Role $role): void
    {
        if ($role->slug === 'system_admin' && ($actor === null || ! $this->isSystemAdmin($actor))) {
            throw new ForbiddenActionException('Only a system administrator may assign the system administrator role.');
        }
    }

    private function assertCanManageTarget(?User $actor, User $target): void
    {
        if (
            $this->isSystemAdminUser($target)
            && ($actor === null || ! $this->isSystemAdmin($actor))
        ) {
            throw new ForbiddenActionException('Only a system administrator may manage a system administrator account.');
        }
    }

    private function assertAtLeastOneActiveSystemAdminRemains(int $leaving = 1): void
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        if ($roleId === null) {
            throw new BusinessRuleException('The system administrator role is not configured.');
        }

        $activeIds = User::query()
            ->where('role_id', $roleId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($activeIds->count() - $leaving < 1) {
            throw new BusinessRuleException('At least one active system administrator must remain.');
        }
    }

    /** @return array<string, mixed> */
    private function userSnapshot(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role_slug' => $user->role?->slug,
            'employee_id' => $user->employee_id,
            'is_active' => (bool) $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'locked_until' => $user->locked_until?->toIso8601String(),
        ];
    }

    private function encodeId(int $id): string
    {
        return app('hashids')->encode($id);
    }
}
