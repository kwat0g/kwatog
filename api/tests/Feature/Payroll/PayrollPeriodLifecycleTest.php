<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Tester '.uniqid(),
            'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
        ]);
    }

    public function test_create_then_overlap_rejected(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();

        $svc->create([
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-15',
            'payroll_date' => '2026-04-15',
            'is_first_half' => true,
        ], $user);

        $this->expectException(\RuntimeException::class);
        $svc->create([
            'period_start' => '2026-04-10',
            'period_end'   => '2026-04-25',
            'payroll_date' => '2026-04-25',
            'is_first_half' => true,
        ], $user);
    }

    public function test_cannot_finalize_a_draft_period(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();

        $period = $svc->create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
        ], $user);

        $this->expectException(\RuntimeException::class);
        $svc->finalize($period);
    }

    public function test_approve_then_finalize_sets_correct_status(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();

        $period = $svc->create([
            'period_start' => '2026-05-01', 'period_end' => '2026-05-15',
            'payroll_date' => '2026-05-15', 'is_first_half' => true,
        ], $user);

        $approved = $svc->approve($period);
        $this->assertSame(PayrollPeriodStatus::Approved, $approved->status);

        $finalized = $svc->finalize($approved);
        $this->assertSame(PayrollPeriodStatus::Finalized, $finalized->status);
    }
}
