<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        $plannedStart = fake()->dateTimeBetween('-1 month', '+1 month');
        $plannedEnd   = (clone $plannedStart)->modify('+' . fake()->numberBetween(1, 5) . ' days');

        return [
            'wo_number'           => 'WO-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'product_id'          => Product::factory(),
            'sales_order_id'      => null,
            'sales_order_item_id' => null,
            'mrp_plan_id'         => null,
            'parent_wo_id'        => null,
            'parent_ncr_id'       => null,
            'machine_id'          => null,
            'mold_id'             => null,
            'quantity_target'     => fake()->numberBetween(100, 5000),
            'quantity_produced'   => 0,
            'quantity_good'       => 0,
            'quantity_rejected'   => 0,
            'scrap_rate'          => 0,
            'planned_start'       => $plannedStart->format('Y-m-d H:i:s'),
            'planned_end'         => $plannedEnd->format('Y-m-d H:i:s'),
            'actual_start'        => null,
            'actual_end'          => null,
            'status'              => 'planned',
            'pause_reason'        => null,
            'priority'            => fake()->numberBetween(1, 5),
            'created_by'          => User::factory(),
            'batch_number'        => null,
            'material_lot_references' => null,
        ];
    }
}
