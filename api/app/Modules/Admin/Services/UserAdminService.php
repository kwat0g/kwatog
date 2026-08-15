<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\AuditLog;
use App\Common\Services\TemporaryPasswordGenerator;
use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    ) {}

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
            $roleId = Role::tryDecodeHash((string) $filters['role_id']);
            if ($roleId) {
                $query->where('role_id', $roleId);
            }
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
            $deptId = \App\Modules\HR\Models\Department::tryDecodeHash((string) $filters['department_id']);
            if ($deptId) {
                $query->whereHas('employee', fn ($q) => $q->where('department_id', $deptId));
            }
        }

        $sort = $filters['sort'] ?? 'last_activity';
        $dir = $filters['direction'] ?? 'desc';
        $allowed = ['name', 'email', 'last_activity', 'created_at'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        return $query->paginate($perPage);
    }

    public function show(User $user): User
    {
        return $user->load(['role', 'employee.department', 'employee.position']);
    }

    public function createStandalone(array $data): \App\Modules\Admin\Support\CreatedUser
    {
        return DB::transaction(function () use ($data) {
            $tempPassword = $data['temp_password'] ?? $this->temporaryPasswords->generate();

            /** @var User $user */
            $user = User::create([
                'name'                  => $data['name'],
                'email'                 => $data['email'],
                'password'              => Hash::make($tempPassword),
                'role_id'               => $data['role_id'],
                'employee_id'           => null,
                'is_active'             => true,
                'must_change_password'  => true,
                'failed_login_attempts' => 0,
            ]);

            \App\Modules\Auth\Models\PasswordHistory::create([
                'user_id'       => $user->id,
                'password_hash' => $user->password,
                'created_at'    => now(),
            ]);

            return new \App\Modules\Admin\Support\CreatedUser(
                user: $user->fresh(['role']),
                tempPassword: (string) $tempPassword,
            );
        });
    }

    public function unlock(User $user): User
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ])->save();
        return $user->fresh(['role']);
    }

    public function deactivate(User $user): User
    {
        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->flushPermissionsCache();
        });
        return $user->fresh(['role']);
    }

    public function activate(User $user): User
    {
        $user->update(['is_active' => true]);
        return $user->fresh(['role']);
    }

    public function changeRole(User $user, int $roleId, int $expectedRoleId, string $reason = ''): User
    {
        return DB::transaction(function () use ($user, $roleId, $expectedRoleId, $reason) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ((int) $locked->role_id !== $expectedRoleId) {
                throw new ConflictHttpException(
                    'This user role changed while you were editing. Refresh the user and retry with the current role.'
                );
            }

            $oldRoleId = (int) $locked->role_id;
            $oldRole = Role::query()->find($oldRoleId);
            $newRole = Role::query()->findOrFail($roleId);
            $locked->update(['role_id' => $roleId]);

            Cache::forget("auth:role_perms:{$oldRoleId}");
            $locked->flushPermissionsCache();

            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'role_changed',
                'model_type' => $locked->getMorphClass(),
                'model_id'   => $locked->id,
                'old_values' => [
                    'role_id'   => $oldRoleId,
                    'role_slug' => $oldRole?->slug,
                ],
                'new_values' => [
                    'role_id'   => $newRole->id,
                    'role_slug' => $newRole->slug,
                    'reason'    => trim($reason) ?: 'Admin role assignment',
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $locked->fresh(['role']);
        });
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  int  $roleId
     * @param  string  $reason
     * @param  array<int, int>  $expectedRoleIds  keyed by user ID
     * @return array{updated: int, conflicts: array<int, array{user_id: string, expected_role_id: ?string, actual_role_id: ?string}>}
     */
    public function bulkChangeRole(array $userIds, int $roleId, string $reason = '', array $expectedRoleIds = []): array
    {
        return DB::transaction(function () use ($userIds, $roleId, $reason, $expectedRoleIds) {
            $users = User::query()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'name', 'role_id']);

            // Capture old role IDs before update.
            $oldRoleIds = $users->pluck('role_id', 'id')->all();
            $conflicts = [];
            $writable = [];
            foreach ($users as $u) {
                $expected = $expectedRoleIds[$u->id] ?? null;
                if ($expected === null || (int) $u->role_id !== (int) $expected) {
                    $conflicts[] = [
                        'user_id' => $u->hash_id,
                        'expected_role_id' => $expected !== null ? Role::find($expected)?->hash_id : null,
                        'actual_role_id' => $u->role_id !== null ? Role::find($u->role_id)?->hash_id : null,
                    ];
                    continue;
                }
                $writable[] = $u->id;
            }

            if ($writable === []) {
                return ['updated' => 0, 'conflicts' => $conflicts];
            }

            $changedOldRoleIds = array_intersect_key($oldRoleIds, array_flip($writable));

            User::query()
                ->whereIn('id', $writable)
                ->update(['role_id' => $roleId]);

            // Flush permissions cache for each affected user.
            foreach ($users as $u) {
                if (in_array($u->id, $writable, true)) {
                    Cache::forget("auth:role_perms:{$u->role_id}");
                    $u->flushPermissionsCache();
                }
            }

            // Write audit log entry for the bulk action.
            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'bulk_role_change',
                'model_type' => 'App\Modules\Auth\Models\User',
                'model_id'   => null, // Null for bulk actions.
                'old_values' => [
                    'user_ids' => $writable,
                    'old_role_ids' => $changedOldRoleIds,
                ],
                'new_values' => [
                    'user_ids' => $writable,
                    'new_role_id' => $roleId,
                    'reason' => $reason,
                    'conflicts' => $conflicts,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return ['updated' => count($writable), 'conflicts' => $conflicts];
        });
    }

    public function resetPassword(User $user): string
    {
        return $this->provisioning->resetPasswordForUser($user);
    }

    public function loginHistory(User $user, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return LoginHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
