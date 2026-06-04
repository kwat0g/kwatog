<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Auth\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role_id' => fn () => Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee'])->id,
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'theme_mode' => 'system',
            'sidebar_collapsed' => false,
        ];
    }

    /**
     * Assign a specific role by slug.
     * Creates the role record if it doesn't exist yet (test environments).
     */
    public function withRole(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::firstOrCreate(
                ['slug' => $slug],
                ['name'  => ucwords(str_replace('_', ' ', $slug))],
            )->id,
        ]);
    }
}
