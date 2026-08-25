<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\SettingsService;
use App\Common\Services\TemporaryPasswordGenerator;
use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Exceptions\AccountAlreadyProvisionedException;
use App\Modules\HR\Exceptions\EmployeeNoLongerExistsException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * U1 — provisions, deactivates, and resets system accounts linked to an
 * employee. Bidirectional link via users.employee_id (unique FK after 0118).
 */
class UserProvisioningService
{
    /** Roles that may be assigned by the employee-account HR workflow. */
    private const ASSIGNABLE_ROLE_SLUGS = ['employee'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TemporaryPasswordGenerator $temporaryPasswords,
    ) {}
    /**
     * @param  array{send_welcome?: bool}  $options
     *
     * @throws AccountAlreadyProvisionedException when the employee already has an account
     * @throws EmployeeNoLongerExistsException when the employee was removed before the operation ran
     */
    public function provisionForEmployee(Employee $employee, array $options = []): User
    {
        return DB::transaction(function () use ($employee, $options) {
            $lockedEmployee = Employee::query()
                ->lockForUpdate()
                ->find($employee->id);
            if (! $lockedEmployee) {
                throw new EmployeeNoLongerExistsException('Employee no longer exists.');
            }
            if ($lockedEmployee->user()->exists()) {
                throw new AccountAlreadyProvisionedException('Employee already has a system account.');
            }

            $email = $this->resolveEmail($lockedEmployee);
            $tempPassword = $this->generateTempPassword();

            /** @var User $user */
            $user = User::create([
                'name'                  => $lockedEmployee->full_name,
                'email'                 => $email,
                'password'              => Hash::make($tempPassword),
                'role_id'               => $this->defaultRoleIdForEmployee(),
                'employee_id'           => $lockedEmployee->id,
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
                DB::afterCommit(fn () => $user->notify(new EmployeeWelcomeNotification($tempPassword)));
            }

            return $user->fresh(['role']);
        });
    }

    public function deactivateForEmployee(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $lockedEmployee = Employee::query()
                ->lockForUpdate()
                ->find($employee->id);
            if (! $lockedEmployee) {
                return;
            }

            /** @var User|null $user */
            $user = $lockedEmployee->user()
                ->lockForUpdate()
                ->first();
            if (! $user) {
                return;
            }

            $user->update(['is_active' => false]);
            $this->revokeSessionsAndTokens($user);
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

            // Resetting credentials must terminate every prior session before
            // the new temporary password can be used. Delivery is queued only
            // after this transaction commits.
            $this->revokeSessionsAndTokens($user);
            DB::afterCommit(fn () => $user->notify(new EmployeePasswordResetNotification($temp)));

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
        $domain = $this->settings->requiredString('company.employee_email_domain');

        $email = "{$base}@{$domain}";
        $count = 1;
        while (User::query()->where('email', $email)->exists()) {
            $email = "{$base}{$count}@{$domain}";
            $count++;
        }
        return $email;
    }

    private function resolveEmail(Employee $employee): string
    {
        $employeeEmail = trim((string) $employee->email);
        if ($employeeEmail !== '' && filter_var($employeeEmail, FILTER_VALIDATE_EMAIL) !== false) {
            if (User::query()->where('email', $employeeEmail)->exists()) {
                throw new \DomainException('The employee email is already linked to another system account.');
            }

            return strtolower($employeeEmail);
        }

        return $this->generateEmail($employee);
    }

    /**
     * Generate a policy-compliant temporary password.
     */
    private function generateTempPassword(): string
    {
        return $this->temporaryPasswords->generate();
    }

    private function defaultRoleIdForEmployee(): int
    {
        $slug = $this->settings->requiredString('hr.default_user_role_slug');
        if (! in_array($slug, self::ASSIGNABLE_ROLE_SLUGS, true)) {
            throw new \DomainException("Configured employee provisioning role [{$slug}] is not assignable by HR.");
        }
        $role = Role::query()->where('slug', $slug)->first();
        abort_if(! $role, 500, "Configured default role [{$slug}] does not exist.");
        return (int) $role->id;
    }

    private function revokeSessionsAndTokens(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
