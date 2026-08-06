<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL', ''));
        $name = trim((string) env('ADMIN_NAME', ''));
        $password = (string) env('ADMIN_PASSWORD', '');
        if ($email === '' || $name === '' || $password === '') {
            $this->command?->warn('Admin bootstrap skipped: set ADMIN_EMAIL, ADMIN_NAME, and ADMIN_PASSWORD to create the initial administrator.');
            return;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new RuntimeException('ADMIN_EMAIL must be valid and ADMIN_PASSWORD must be at least 12 characters.');
        }
        $role = Role::where('slug', 'system_admin')->firstOrFail();

        $hash = Hash::make($password);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'                  => $name,
                'password'              => $hash,
                'role_id'               => $role->id,
                'is_active'             => true,
                'must_change_password'  => false,
                'password_changed_at'   => now(),
                'theme_mode'            => 'system',
            ],
        );

        $this->command?->info("System Admin {$user->email} ready.");
    }
}
