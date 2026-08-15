<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeMinimisBenefitType;
use App\Modules\Payroll\Services\DeMinimisService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P83 (de-minimis benefit recording, money): the de_minimis_benefits table is
 * protected by the `de_minimis_unique_employee_month` unique constraint, so a
 * concurrent double-record can never inflate YTD past the statutory annual
 * limit — the second insert collides. The service now surfaces that collision
 * as a clean business rule instead of a raw DB 500, and the YTD stays capped.
 */
class DeMinimisRecordInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_duplicate_month_record_is_rejected_and_ytd_stays_capped(): void
    {
        $employee = Employee::factory()->create();
        app(SettingsService::class)->set('payroll.de_minimis.uniform_allowance.annual_limit', 90000);

        $svc = app(DeMinimisService::class);
        $svc->record($employee, DeMinimisBenefitType::UniformAllowance, '80000.00', 2026, 8);

        // Second record for the same employee/type/month collides with the
        // unique constraint — must surface as a business rule, not a raw 500.
        try {
            $svc->record($employee, DeMinimisBenefitType::UniformAllowance, '80000.00', 2026, 8);
            $this->fail('A duplicate de-minimis record for the month must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already recorded', strtolower($e->getMessage()));
        }

        $this->assertSame(
            '80000.00',
            $svc->getYtdTotal($employee, DeMinimisBenefitType::UniformAllowance, 2026),
            'YTD must not be inflated by the rejected duplicate.'
        );
    }
}
