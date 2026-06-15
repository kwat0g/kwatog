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
            'is_auto_generated'      => false,
            'auto_generated_reason'  => null,
            'is_urgent'              => false,
            'urgency_reason'         => null,
        ];
    }

    /**
     * status / current_approval_step / submitted_at / approved_at are
     * non-fillable on the model. Factory rows write them via forceFill.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (PurchaseRequest $pr) {
            if (! $pr->status) {
                $pr->forceFill([
                    'status'                => 'draft',
                    'current_approval_step' => 0,
                    'submitted_at'          => null,
                    'approved_at'           => null,
                ]);
            }
        });
    }
}
