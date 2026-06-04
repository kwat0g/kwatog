<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    public function definition(): array
    {
        return [
            'pr_number'              => 'PR-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'requested_by'           => User::factory(),
            'department_id'          => Department::factory(),
            'mrp_plan_id'            => null,
            'template_id'            => null,
            'date'                   => fake()->date(),
            'reason'                 => fake()->sentence(),
            'priority'               => 'normal',
            'status'                 => 'draft',
            'is_auto_generated'      => false,
            'auto_generated_reason'  => null,
            'is_urgent'              => false,
            'urgency_reason'         => null,
            'current_approval_step'  => null,
            'submitted_at'           => null,
            'approved_at'            => null,
        ];
    }
}
