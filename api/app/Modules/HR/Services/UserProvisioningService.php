<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * U1 — provisions, deactivates, and resets system accounts linked to an
 * employee. Bidirectional link via users.employee_id (unique FK after 0118).
 */
class UserProvisioningService
{
    /**
     * @param  array{email?: string, role_id?: int, send_welcome?: bool}  $options
     *
     * @throws \DomainException when the employee already has an account
     */
    public function provisionForEmployee(Employee $employee, array $options = []): User
    {
        if ($employee->user()->exists()) {
            throw new \DomainException('Employee already has a system account.');
        }

        return DB::transaction(function () use ($employee, $options) {
            $email = $options['email'] ?? $this->generateEmail($employee);
            $tempPassword = $this->generateTempPassword();

            /** @var User $user */
            $user = User::create([
                'name'                  => $employee->full_name,
                'email'                 => $email,
                'password'              => Hash::make($tempPassword),
                'role_id'               => $options['role_id'] ?? $this->defaultRoleIdForEmployee(),
                'employee_id'           => $employee->id,
                'is_active'             => true,
                'must_change_password'  => true,
                'password_changed_at'   => null,
                'failed_login_attempts' => 0,
            ]);

            PasswordHistory::create([
                'user_id'       => $user->id,
                'password_hash' => $user->password,
                'created_at'    => now(),
            ]);

            if (($options['send_welcome'] ?? true) === true) {
                $user->notify(new WelcomeNotification($tempPassword));
            }

            return $user->fresh(['role']);
        });
    }

    public function deactivateForEmployee(Employee $employee): void
    {
        /** @var User|null $user */
        $user = $employee->user;
        if (! $user) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);
            // Revoke any sanctum tokens (no-op if none).
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
            // Delete active web sessions belonging to this user.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->flushPermissionsCache();
        });
    }

    /**
     * Generate a new temp password, force change on login, send notification.
     * Returns the temp password to the caller (not echoed in API responses).
     */
    public function resetPasswordForEmployee(Employee $employee): string
    {
        /** @var User|null $user */
        $user = $employee->user;
        abort_if(! $user, 404, 'Employee has no system account.');

        return $this->resetPasswordForUser($user);
    }

    public function resetPasswordForUser(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $temp = $this->generateTempPassword();

            PasswordHistory::create([
                'user_id'       => $user->id,
                'password_hash' => $user->password,
                'created_at'    => now(),
            ]);

            $user->forceFill([
                'password'              => Hash::make($temp),
                'must_change_password'  => true,
                'failed_login_attempts' => 0,
                'locked_until'          => null,
                'password_changed_at'   => now(),
            ])->save();

            $user->notify(new PasswordResetNotification($temp));

            return $temp;
        });
    }

    /**
     * @return array{
     *   account_exists: bool,
     *   is_active: bool,
     *   is_locked: bool,
     *   email: ?string,
     *   role: ?array{id: string, name: string, slug: string},
     *   user_id: ?string,
     *   last_login_at: ?string,
     *   must_change_password: bool,
     * }
     */
    public function accountStatusForEmployee(Employee $employee): array
    {
        /** @var User|null $user */
        $user = $employee->user()->with('role')->first();

        if (! $user) {
            return [
                'account_exists'        => false,
                'is_active'             => false,
                'is_locked'             => false,
                'email'                 => null,
                'role'                  => null,
                'user_id'               => null,
                'last_login_at'         => null,
                'must_change_password'  => false,
            ];
        }

        return [
            'account_exists' => true,
            'is_active'      => (bool) $user->is_active,
            'is_locked'      => $user->isLocked(),
            'email'          => $user->email,
            'role'           => $user->role
                ? ['id' => $user->role->hash_id, 'name' => $user->role->name, 'slug' => $user->role->slug]
                : null,
            'user_id'              => $user->hash_id,
            'last_login_at'        => optional($user->last_activity)->toIso8601String(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }

    /**
     * Bulk provision multiple employees. Each runs in its own transaction
     * so a single failure does not roll the whole batch back.
     *
     * @param  array<int, int>  $employeeIds  raw integer ids
     * @return array<int, array{employee_id: string, status: string, message: string, user_id?: string}>
     */
    public function bulkProvision(array $employeeIds, array $options = []): array
    {
        $results = [];
        $employees = Employee::query()->whereIn('id', $employeeIds)->get();

        foreach ($employees as $employee) {
            try {
                $user = $this->provisionForEmployee($employee, $options);
                $results[] = [
                    'employee_id' => $employee->hash_id,
                    'status'      => 'success',
                    'message'     => 'Account created.',
                    'user_id'     => $user->hash_id,
                ];
            } catch (\DomainException $e) {
                $results[] = [
                    'employee_id' => $employee->hash_id,
                    'status'      => 'skipped',
                    'message'     => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = [
                    'employee_id' => $employee->hash_id,
                    'status'      => 'failed',
                    'message'     => 'Provisioning failed: '.$e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function generateEmail(Employee $employee): string
    {
        $base = strtolower(($employee->first_name ?? '').'.'.($employee->last_name ?? ''));
        $base = preg_replace('/[^a-z.]/', '', $base) ?: 'employee';
        $base = trim($base, '.') ?: 'employee';
        $domain = config('app.employee_email_domain', 'ogami.ph');

        $email = "{$base}@{$domain}";
        $count = 1;
        while (User::query()->where('email', $email)->exists()) {
            $email = "{$base}{$count}@{$domain}";
            $count++;
        }
        return $email;
    }

    /**
     * 12-char random password with upper, lower, number, symbol.
     * Uses Laravel's Str::password which guarantees policy compliance.
     */
    private function generateTempPassword(): string
    {
        return Str::password(12, true, true, true, false);
    }

    private function defaultRoleIdForEmployee(): int
    {
        $role = Role::query()->where('slug', 'employee')->first()
            ?? Role::query()->where('slug', 'self_service')->first();
        if (! $role) {
            // Fallback to lowest-priv role (any non-admin).
            $role = Role::query()->where('slug', '!=', 'system_admin')->orderBy('id')->first();
        }
        abort_if(! $role, 500, 'No default role configured.');
        return (int) $role->id;
    }
}
