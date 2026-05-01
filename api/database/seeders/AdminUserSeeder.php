<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('slug', 'system_admin')->firstOrFail();

        $email = 'admin@ogami.test';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $this->command?->info("Admin user {$email} already exists.");
            return;
        }

        // Random strong password — saved to storage so the admin can rotate on first login.
        $password = $this->generatePassword();

        User::create([
            'name'                  => 'System Administrator',
            'email'                 => $email,
            'password'              => Hash::make($password),
            'role_id'               => $role->id,
            'is_active'             => true,
            'must_change_password'  => true,
            'password_changed_at'   => now(),
            'theme_mode'            => 'system',
        ]);

        $path = storage_path('app/admin-credentials.txt');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, "Email:    {$email}\nPassword: {$password}\n");
        @chmod($path, 0600);

        $this->command?->info("System Admin created: {$email}");
        $this->command?->warn("Password saved to: {$path} (must change on first login).");
    }

    private function generatePassword(): string
    {
        // Guaranteed to satisfy our policy.
        do {
            $candidate = Str::password(length: 16, letters: true, numbers: true, symbols: true, spaces: false);
        } while (
            ! preg_match('/[A-Z]/', $candidate)
            || ! preg_match('/[0-9]/', $candidate)
            || ! preg_match('/[^A-Za-z0-9]/', $candidate)
        );
        return $candidate;
    }
}
