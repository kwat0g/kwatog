<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Fixed demo password. Idempotent — running the seeder multiple times keeps
     * the same credentials, never rotates them. Production deployments should
     * either change the constant or rotate via the admin profile page on first
     * login.
     */
    private const DEFAULT_PASSWORD = 'password';
    private const EMAIL            = 'admin@ogami.test';

    public function run(): void
    {
        $role = Role::where('slug', 'system_admin')->firstOrFail();

        $hash = Hash::make(self::DEFAULT_PASSWORD);

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name'                  => 'System Administrator',
                'password'              => $hash,
                'role_id'               => $role->id,
                'is_active'             => true,
                'must_change_password'  => false, // demo seed: skip the forced rotation flow
                'password_changed_at'   => now(),
                'theme_mode'            => 'system',
            ],
        );

        $this->command?->info("System Admin {$user->email} ready (password: " . self::DEFAULT_PASSWORD . ').');
    }
}
