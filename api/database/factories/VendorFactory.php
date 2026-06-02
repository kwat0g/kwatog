<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'name'               => fake()->company(),
            'contact_person'     => fake()->name(),
            'email'              => fake()->companyEmail(),
            'phone'              => fake()->phoneNumber(),
            'payment_terms_days' => 30,
            'is_active'          => true,
        ];
    }
}
