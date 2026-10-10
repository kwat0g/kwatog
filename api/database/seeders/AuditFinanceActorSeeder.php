<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuditFinanceActorSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Audit-only Finance actors require AUDIT_E2E_TESTING=true outside production.');
        }

        $roleId = Role::query()->where('slug', 'finance_officer')->value('id');
        if (! $roleId) {
            throw new RuntimeException('The finance_officer role must be seeded first.');
        }

        User::query()->updateOrCreate(
            ['email' => 'finance3@ogami.test'],
            [
                'name' => 'Audit Finance Three',
                'password' => Hash::make('password'),
                'role_id' => $roleId,
                'is_active' => true,
            ],
        );
    }
}
