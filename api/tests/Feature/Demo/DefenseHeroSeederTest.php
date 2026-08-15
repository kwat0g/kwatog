<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\DefenseHeroSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Track C — DefenseHeroSeeder contract.
 *
 *   Additive + immutable: run twice → identical state. The hero AR invoice is
 *   a DRAFT produced from the real DeliveryService::confirm() handoff — it
 *   carries delivery_id and invoice items (genuine provenance), never a
 *   directly-fabricated row.
 */
class DefenseHeroSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedHeroChainPrerequisites();
    }

    public function test_seeder_is_additive_and_idempotent(): void
    {
        $this->seed(DefenseHeroSeeder::class);

        $invoicesAfterFirst = DB::table('invoices')->count();
        $periodsAfterFirst  = DB::table('accounting_periods')->count();
        $balancesAfterFirst = DB::table('employee_leave_balances')->count();
        $rowsAfterFirst = $this->heroStateSnapshot();

        $this->seed(DefenseHeroSeeder::class);

        $this->assertSame($invoicesAfterFirst, DB::table('invoices')->count(),
            'Running the seeder twice must not create a second invoice.');
        $this->assertSame($periodsAfterFirst, DB::table('accounting_periods')->count(),
            'Running the seeder twice must not duplicate the accounting period.');
        $this->assertSame($balancesAfterFirst, DB::table('employee_leave_balances')->count(),
            'Running the seeder twice must not duplicate leave balances.');
        $this->assertSame($rowsAfterFirst, $this->heroStateSnapshot(),
            'Running the seeder twice must not rewrite any existing hero or support row.');

        $this->assertSame(1, $invoicesAfterFirst, 'Exactly one hero invoice is produced.');
    }

    public function test_hero_invoice_is_a_draft_with_delivery_provenance(): void
    {
        $this->seed(DefenseHeroSeeder::class);

        $invoice = DB::table('invoices')->first();
        $this->assertNotNull($invoice, 'The hero invoice must exist after seeding.');
        $this->assertSame('draft', $invoice->status, 'The hero invoice is a draft for Finance to finalize.');
        $this->assertNotNull($invoice->delivery_id, 'The hero invoice must be produced FROM a delivery (no orphans).');
        $this->assertNotNull($invoice->sales_order_id, 'The hero invoice must be linked to its sales order.');

        $delivery = Delivery::find($invoice->delivery_id);
        $this->assertNotNull($delivery);
        $this->assertSame('confirmed', $delivery->status->value, 'Confirming the delivery drove the invoice handoff.');

        $itemCount = DB::table('invoice_items')->where('invoice_id', $invoice->id)->count();
        $this->assertSame(1, $itemCount, 'The hero invoice must carry real invoice items from the SO line.');
    }

    public function test_seeder_does_not_reopen_periods_reset_balances_or_consume_unrelated_deliveries(): void
    {
        $year = (int) today()->format('Y');
        $month = (int) today()->format('n');
        DB::table('accounting_periods')->insert([
            'year' => $year,
            'month' => $month,
            'status' => 'closed',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $request = DB::table('leave_requests')->first();
        $this->assertNotNull($request);
        DB::table('employee_leave_balances')->insert([
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'year' => $year,
            'total_credits' => '5.00',
            'used' => '3.00',
            'remaining' => '2.00',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $unrelatedDelivery = Delivery::query()->firstOrFail();

        $this->seed(DefenseHeroSeeder::class);

        $this->assertSame('closed', DB::table('accounting_periods')
            ->where('year', $year)->where('month', $month)->value('status'));
        $balance = DB::table('employee_leave_balances')
            ->where('employee_id', $request->employee_id)
            ->where('leave_type_id', $request->leave_type_id)
            ->where('year', $year)
            ->first();
        $this->assertNotNull($balance);
        $this->assertSame(3.0, (float) $balance->used);
        $this->assertSame(2.0, (float) $balance->remaining);

        $unrelatedDelivery->refresh();
        $this->assertSame('delivered', $unrelatedDelivery->status->value);
        $this->assertNull($unrelatedDelivery->invoice_id);
        $this->assertSame(0, $unrelatedDelivery->proofs()->count());
        $this->assertDatabaseHas('deliveries', ['delivery_number' => 'DEL-DEF-HERO-001']);
    }

    public function test_seeder_does_not_override_an_explicitly_disabled_accounting_module(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'modules.accounting'],
            [
                'value' => json_encode(false),
                'group' => 'modules',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->seed(DefenseHeroSeeder::class);

        $this->assertSame('false', DB::table('settings')->where('key', 'modules.accounting')->value('value'));
        $this->assertSame(0, DB::table('invoices')->count());
    }

    /* ─── Fixtures ────────────────────────────────────────────────────── */

    /** @return array<string, string> */
    private function heroStateSnapshot(): array
    {
        $tables = [
            'settings', 'accounts', 'customers', 'products', 'sales_orders',
            'sales_order_items', 'deliveries', 'delivery_items',
            'delivery_proofs', 'invoices', 'invoice_items',
            'accounting_periods', 'employee_leave_balances',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = json_encode(
                DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
                JSON_THROW_ON_ERROR,
            );
        }

        return $snapshot;
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

        $customerId = DB::table('customers')->insertGetId([
            'name'               => 'Hero Customer',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $so = SalesOrder::create([
            'so_number'          => 'SO-HERO-'.substr(uniqid(), -5),
            'customer_id'        => $customerId,
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

        // Leave-request prerequisite so the seeder has a balance to create.
        $dept = \App\Modules\HR\Models\Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = \App\Modules\HR\Models\Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $employee = \App\Modules\HR\Models\Employee::create([
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
