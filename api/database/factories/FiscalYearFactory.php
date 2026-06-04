<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2040);
        return [
            'year'       => $year,
            'start_date' => "{$year}-01-01",
            'end_date'   => "{$year}-12-31",
            'status'     => 'active',
        ];
    }
}
