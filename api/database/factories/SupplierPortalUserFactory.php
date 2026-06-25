<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierPortalUserFactory extends Factory
{
    protected $model = SupplierPortalUser::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'name'      => fake()->name(),
            'email'     => fake()->unique()->safeEmail(),
            'password'  => bcrypt('password'),
        ];
    }
}
