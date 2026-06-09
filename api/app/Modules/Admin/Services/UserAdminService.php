<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\AuditLog;
use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * U2 — central user-management service for the Admin > Users surface.
 */
class UserAdminService
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
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
            $tempPassword = $data['temp_password'] ?? \Illuminate\Support\Str::password(12, true, true, true, false);

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

    public function changeRole(User $user, int $roleId): User
    {
        DB::transaction(function () use ($user, $roleId) {
            $user->update(['role_id' => $roleId]);
            $user->flushPermissionsCache();
        });
        return $user->fresh(['role']);
    }

    /**
     * Bulk update user roles in a transaction.
     *
     * @param  int[]  $userIds
     * @param  int  $roleId
     * @param  string  $reason
     * @return int Count of updated users
     */
    public function bulkChangeRole(array $userIds, int $roleId, string $reason = ''): int
    {
        return DB::transaction(function () use ($userIds, $roleId, $reason) {
            $users = User::query()
                ->whereIn('id', $userIds)
                ->get(['id', 'name', 'role_id']);

            $count = $users->count();

            // Capture old role IDs before update.
            $oldRoleIds = $users->pluck('role_id', 'id')->all();

            User::query()
                ->whereIn('id', $userIds)
                ->update(['role_id' => $roleId]);

            // Flush permissions cache for each affected user.
            foreach ($users as $u) {
                $u->flushPermissionsCache();
            }

            // Write audit log entry for the bulk action.
            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'bulk_role_change',
                'model_type' => 'App\Modules\Auth\Models\User',
                'model_id'   => null, // Null for bulk actions.
                'old_values' => [
                    'user_ids' => $userIds,
                    'old_role_ids' => $oldRoleIds,
                ],
                'new_values' => [
                    'user_ids' => $userIds,
                    'new_role_id' => $roleId,
                    'reason' => $reason,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $count;
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