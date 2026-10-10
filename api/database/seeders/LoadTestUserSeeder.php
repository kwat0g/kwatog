<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LoadTestUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (
            app()->environment('production')
            || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)
            || ! str_starts_with($database, 'ogami_test_')
        ) {
            throw new RuntimeException('Load-test users require AUDIT_E2E_TESTING=true and an ogami_test_* database.');
        }

        $password = Hash::make(self::PASSWORD);
        foreach ([
            'warehouse_staff' => ['warehouse', 200],
            'system_admin' => ['system-admin', 10],
        ] as $roleSlug => [$prefix, $count]) {
            $roleId = Role::query()->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                throw new RuntimeException("The {$roleSlug} role must be seeded first.");
            }

            for ($userId = 1; $userId <= $count; $userId++) {
                $suffix = str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                User::query()->updateOrCreate(
                    ['email' => "audit-load-{$prefix}-{$suffix}@ogami.test"],
                    [
                        'name' => "Load-test {$prefix} {$suffix}",
                        'password' => $password,
                        'role_id' => $roleId,
                        'is_active' => true,
                        'must_change_password' => false,
                        'last_activity' => null,
                        'password_changed_at' => now(),
                        'failed_login_attempts' => 0,
                        'locked_until' => null,
                    ],
                );
            }
        }
    }
}
