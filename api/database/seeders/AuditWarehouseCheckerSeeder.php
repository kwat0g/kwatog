<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuditWarehouseCheckerSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Audit-only Warehouse actors require AUDIT_E2E_TESTING=true outside production.');
        }

        $roleId = Role::query()->where('slug', 'warehouse_staff')->value('id');
        if (! $roleId) {
            throw new RuntimeException('The warehouse_staff role must be seeded first.');
        }

        foreach ([
            'warehouse-checker@ogami.test' => 'Audit Warehouse Checker',
            'warehouse-finisher@ogami.test' => 'Audit Warehouse Finisher',
        ] as $email => $name) {
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'role_id' => $roleId,
                    'is_active' => true,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ],
            );
        }
    }
}
