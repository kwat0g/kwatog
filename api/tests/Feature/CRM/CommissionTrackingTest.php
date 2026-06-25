<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\CommissionStatus;
use App\Modules\CRM\Models\CommissionEarning;
use App\Modules\CRM\Models\CommissionRate;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\CommissionService;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private User $salesRepUser;
    private Employee $salesRep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('slug', 'system_admin')->firstOrFail();
        $this->financeUser = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $this->salesRep = Employee::factory()->create();
        $empRole = Role::where('slug', 'employee')->firstOrFail();
        $this->salesRepUser = User::factory()->create([
            'role_id'     => $empRole->id,
            'is_active'   => true,
            'employee_id' => $this->salesRep->id,
        ]);
    }

    public function test_can_set_commission_rate(): void
    {
        $response = $this->actingAs($this->financeUser)->postJson('/api/v1/crm/commissions/rates', [
            'employee_id'    => $this->salesRep->id,
            'rate'           => '0.0300',
            'effective_from' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('commission_rates', [
            'employee_id' => $this->salesRep->id,
            'rate'        => '0.0300',
        ]);
    }

    public function test_calculate_commission_for_sales_order(): void
    {
        CommissionRate::create([
            'employee_id'    => $this->salesRep->id,
            'rate'           => '0.0500',
            'effective_from' => '2026-01-01',
        ]);

        $so = SalesOrder::factory()->create([
            'sales_rep_id' => $this->salesRep->id,
            'total_amount' => '100000.00',
            'date'         => '2026-06-15',
        ]);

        $service = app(CommissionService::class);
        $earning = $service->calculateForOrder($so);

        $this->assertNotNull($earning);
        $this->assertEquals('5000.00', $earning->commission_amount);
        $this->assertEquals('0.0500', $earning->commission_rate);
        $this->assertEquals(CommissionStatus::Pending, $earning->status);
    }

    public function test_no_commission_without_sales_rep(): void
    {
        $so = SalesOrder::factory()->create(['sales_rep_id' => null, 'total_amount' => '50000.00']);

        $service = app(CommissionService::class);
        $earning = $service->calculateForOrder($so);

        $this->assertNull($earning);
    }

    public function test_self_approval_blocked(): void
    {
        CommissionRate::create([
            'employee_id'    => $this->salesRep->id,
            'rate'           => '0.0300',
            'effective_from' => '2026-01-01',
        ]);

        $so = SalesOrder::factory()->create([
            'sales_rep_id' => $this->salesRep->id,
            'total_amount' => '80000.00',
            'date'       => '2026-06-01',
        ]);

        $service = app(CommissionService::class);
        $earning = $service->calculateForOrder($so);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot approve your own commission earning.');
        $service->approve($earning, $this->salesRepUser);
    }

    public function test_finance_can_approve_earning(): void
    {
        $earning = new CommissionEarning();
        $earning->fill([
            'sales_order_id'    => SalesOrder::factory()->create()->id,
            'employee_id'       => $this->salesRep->id,
            'order_total'       => '100000.00',
            'commission_rate'   => '0.0300',
            'commission_amount' => '3000.00',
        ]);
        $earning->forceFill(['status' => CommissionStatus::Pending->value])->save();

        $service = app(CommissionService::class);
        $result = $service->approve($earning, $this->financeUser);

        $this->assertEquals(CommissionStatus::Approved, $result->status);
        $this->assertEquals($this->financeUser->id, $result->approved_by);
    }

    public function test_batch_mark_paid(): void
    {
        $so = SalesOrder::factory()->create();

        $e1 = new CommissionEarning();
        $e1->fill([
            'sales_order_id' => $so->id, 'employee_id' => $this->salesRep->id,
            'order_total' => '50000.00', 'commission_rate' => '0.03', 'commission_amount' => '1500.00',
        ]);
        $e1->forceFill(['status' => CommissionStatus::Approved->value])->save();

        $e2 = new CommissionEarning();
        $e2->fill([
            'sales_order_id' => SalesOrder::factory()->create()->id, 'employee_id' => $this->salesRep->id,
            'order_total' => '60000.00', 'commission_rate' => '0.03', 'commission_amount' => '1800.00',
        ]);
        $e2->forceFill(['status' => CommissionStatus::Approved->value])->save();

        $service = app(CommissionService::class);
        $count = $service->markPaid([$e1->id, $e2->id], $this->financeUser);

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('commission_earnings', ['id' => $e1->id, 'status' => 'paid']);
        $this->assertDatabaseHas('commission_earnings', ['id' => $e2->id, 'status' => 'paid']);
    }
}
