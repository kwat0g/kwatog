<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Landing\Models;

use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactInquiry>
 */
class ContactInquiryFactory extends Factory
{
    protected $model = ContactInquiry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Column is varchar(30); keep the suffix short.
            'inquiry_no' => 'INQ-T-' . substr((string) uniqid(), -5),
            'full_name' => fake()->name(),
            'company' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+63 917 555 0100',
            'message' => fake()->sentence(12),
            'status' => ContactInquiryStatus::New->value,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ];
    }
}
