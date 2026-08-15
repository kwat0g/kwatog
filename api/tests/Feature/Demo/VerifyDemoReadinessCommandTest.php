<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\DefenseHeroSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Track C — demo:verify command contract.
 *
 *   (1) It is read-only: key-table row counts are identical before and after.
 *   (2) It FAILs (exit 1) on the broken surfaces a free-click panel would
 *       catch — orphan invoices, fabricated paid/partial statuses, a delivery
 *       that never produced an invoice, failed jobs, no accounting period,
 *       no leave balances, missing demo actors.
 *   (3) It PASSes (exit 0) once the hero chain + seed surfaces are in place.
 */
class VerifyDemoReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function seedDemoActors(): void
    {
        $roleId = Role::query()->value('id');
        foreach (['admin@ogami.test', 'portal@supp.test', 'portal@cust.test'] as $email) {
            User::factory()->create(['email' => $email, 'role_id' => $roleId]);
        }
    }

    private function snapshot(): array
    {
        $tables = [
            'users', 'invoices', 'invoice_items', 'collections', 'deliveries',
            'accounting_periods', 'employee_leave_balances', 'event_outbox',
            'chain_step_runs', 'failed_jobs',
        ];

        $snap = [];
        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $snap[$table] = DB::table($table)->count();
            }
        }

        return $snap;
    }

    public function test_verify_is_read_only_and_fails_on_a_bare_db(): void
    {
        $this->seedDemoActors();
        $before = $this->snapshot();

        $exit = Artisan::call('demo:verify');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'A bare DB must fail the provenance-critical checks.');
        $this->assertStringContainsString('orphan', strtolower($output));
        $this->assertStringContainsString('FAIL', $output);
        $this->assertSame($before, $this->snapshot(), 'demo:verify must never write a single row.');
    }

    public function test_verify_flags_fabricated_paid_invoice(): void
    {
        $this->seedDemoActors();
        $roleId = Role::query()->value('id');
        $userId = User::factory()->create(['role_id' => $roleId])->id;

        DB::table('invoices')->insert([
            'invoice_number' => 'INV-HERO-BAD',
            'customer_id'    => $this->createCustomer(),
            'date'           => today()->toDateString(),
            'due_date'       => today()->addDays(30)->toDateString(),
            'total_amount'   => '5000.00',
            'balance'        => '0.00',
            'status'         => 'paid',
            'created_by'     => $userId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Artisan::call('demo:verify');
        $output = Artisan::output(); // fetch() consumes the buffer — capture once

        $this->assertStringContainsString('paid/partial', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_verify_passes_after_hero_chain_is_seeded(): void
    {
        $this->seedDemoActors();
        $this->seedHeroChainPrerequisites();

        $this->seed(DefenseHeroSeeder::class);
        $this->seed(DefenseHeroSeeder::class); // idempotency, twice

        $exit = Artisan::call('demo:verify');

        $this->assertSame(0, $exit, 'The hero chain + seed surfaces must satisfy every critical check: '.Artisan::output());
    }

    /* ─── Fixtures ────────────────────────────────────────────────────── */

    private function createCustomer(): int
    {
        return DB::table('customers')->insertGetId([
            'name'               => 'Hero Customer',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function seedHeroChainPrerequisites(): void
    {
        $roleId = Role::query()->value('id');
        $user = User::factory()->create(['role_id' => $roleId]);

        // Sales-revenue account the delivery→invoice handoff resolves.
        DB::table('accounts')->updateOrInsert(
            ['code' => '4010'],
            ['name' => 'Sales Revenue', 'type' => 'revenue', 'normal_balance' => 'credit',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $product = Product::create([
            'part_number'     => 'HERO-PROD-001',
            'name'            => 'Hero Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);

        $so = SalesOrder::create([
            'so_number'          => 'SO-HERO-'.substr(uniqid(), -5),
            'customer_id'        => $this->createCustomer(),
            'date'               => today()->toDateString(),
            'subtotal'           => 100.00,
            'vat_amount'         => 12.00,
            'total_amount'       => 112.00,
            'status'             => 'confirmed',
            'payment_terms_days' => 30,
            'created_by'         => $user->id,
        ]);

        SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id'     => $product->id,
            'quantity'       => 10,
            'unit_price'     => 10.00,
            'total'          => 100.00,
            'delivery_date'  => Carbon::today()->addDays(7)->toDateString(),
        ]);

        Delivery::create([
            'delivery_number' => 'DEL-HERO-'.substr(uniqid(), -5),
            'sales_order_id'  => $so->id,
            'status'          => 'delivered',
            'scheduled_date'  => today()->toDateString(),
            'delivered_at'    => now(),
            'created_by'      => $user->id,
        ]);

        // Leave request prerequisites so the seeder has balances to create.
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $employee = Employee::create([
            'employee_no'          => 'OGM-HERO-001',
            'first_name'           => 'Hero',
            'last_name'            => 'Worker',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'date_hired'           => '2024-01-01',
            'status'               => 'active',
        ]);

        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'name'                         => 'Service Incentive Leave',
            'code'                         => 'SIL-HERO',
            'default_balance'              => 5.0,
            'is_paid'                      => true,
            'requires_document'            => false,
            'is_convertible_on_separation' => true,
            'is_convertible_year_end'      => false,
            'conversion_rate'              => 1.00,
            'is_active'                    => true,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);

        DB::table('leave_requests')->insert([
            'leave_request_no' => 'LR-HERO-001',
            'employee_id'      => $employee->id,
            'leave_type_id'    => $leaveTypeId,
            'start_date'       => today()->addDays(5)->toDateString(),
            'end_date'         => today()->addDays(7)->toDateString(),
            'days'             => 2.0,
            'reason'           => 'Demo hero leave request',
            'status'           => 'pending_hr',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
