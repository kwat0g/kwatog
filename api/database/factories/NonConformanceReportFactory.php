<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonConformanceReport>
 */
class NonConformanceReportFactory extends Factory
{
    protected $model = NonConformanceReport::class;

    public function definition(): array
    {
        return [
            'ncr_number'          => 'NCR-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'source'              => 'inspection_fail',
            'severity'            => 'minor',
            'status'              => 'open',
            'product_id'          => null,
            'inspection_id'       => null,
            'complaint_id'        => null,
            'defect_description'  => fake()->sentence(),
            'affected_quantity'   => fake()->numberBetween(1, 50),
            'disposition'         => null,
            'root_cause'          => null,
            'corrective_action'   => null,
            'created_by'          => User::factory(),
            'assigned_to'         => null,
            'closed_by'           => null,
            'closed_at'           => null,
            'replacement_work_order_id' => null,
            'is_auto_generated'   => false,
        ];
    }
}
