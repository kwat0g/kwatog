<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'fiscal_year_id'  => FiscalYear::factory(),
            'department_id'   => Department::factory(),
            'budget_type'     => 'department',
            'name'            => fake()->words(3, true),
            'total_allocated' => fake()->randomFloat(2, 5000, 100000),
            'total_spent'     => 0.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ];
    }
}
