<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

class PpapSubmissionFactory extends Factory
{
    protected $model = PpapSubmission::class;

    public function definition(): array
    {
        return [
            'ppap_number'      => 'PP-T-' . fake()->unique()->numerify('#####'),
            'vendor_id'        => Vendor::factory(),
            'item_id'          => Item::factory(),
            'ppap_level'       => PpapLevel::Level3->value,
            'submission_date'  => fake()->date(),
            'status'           => PpapStatus::Draft->value,
        ];
    }
}
