<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollVarianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_variance_compares_two_periods(): void
    {
        $p1 = PayrollPeriod::factory()->create(['status' => 'finalized']);
        $p2 = PayrollPeriod::factory()->create(['status' => 'finalized']);
        Payroll::factory()->create(['payroll_period_id' => $p1->id, 'gross_pay' => 10000, 'net_pay' => 8500, 'total_deductions' => 1500]);
        Payroll::factory()->create(['payroll_period_id' => $p2->id, 'gross_pay' => 11000, 'net_pay' => 9000, 'total_deductions' => 2000]);

        $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/payroll-periods/{$p2->hash_id}/variance?compare_to={$p1->hash_id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'current', 'previous',
                    'delta'      => ['gross', 'net', 'deductions', 'headcount'],
                    'pct_change' => ['gross', 'net', 'deductions', 'headcount'],
                ],
            ]);
    }

    public function test_variance_returns_422_without_compare_to(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'finalized']);

        $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/variance")
            ->assertStatus(422);
    }
}
