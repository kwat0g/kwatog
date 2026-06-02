<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\HrDashboardService;
use App\Modules\Dashboard\Services\PlantManagerDashboardService;
use App\Modules\Dashboard\Services\PpcDashboardService;
use App\Modules\Dashboard\Services\PurchasingDashboardService;
use App\Modules\Dashboard\Services\RoleDashboardService;
use App\Modules\Dashboard\Services\WarehouseDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P4.1: private-method reflection targets updated to new owning service classes.
 * Public-method assertions (plantManager) still go through RoleDashboardService facade.
 * All assertions are identical to the pre-split version.
 */
class RoleDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RoleDashboardService
    {
        return app(RoleDashboardService::class);
    }

    /** OTD must read `delivered_at`, not the non-existent `actual_delivery_date`. */
    public function test_otd_rate_counts_on_time_deliveries_using_delivered_at(): void
    {
        // Bootstrap FK prerequisites: role → user → customer → sales_order
        DB::table('roles')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Tester',
            'slug'       => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Test Creator',
            'email'      => 'creator@test.local',
            'password'   => bcrypt('Password1!'),
            'role_id'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customers')->insertOrIgnore([
            'id'                 => 1,
            'name'               => 'Acme',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $soId = DB::table('sales_orders')->insertGetId([
            'so_number'          => 'SO-TEST-0001',
            'customer_id'        => 1,
            'date'               => now()->toDateString(),
            'subtotal'           => '0.00',
            'vat_amount'         => '0.00',
            'total_amount'       => '0.00',
            'status'             => 'delivered',
            'payment_terms_days' => 30,
            'created_by'         => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        // 1 on-time (delivered_at <= scheduled_date), 1 late.
        DB::table('deliveries')->insert([
            ['delivery_number' => 'DLV-1', 'sales_order_id' => $soId, 'status' => 'delivered',
             'scheduled_date' => now()->subDays(2)->toDateString(), 'delivered_at' => now()->subDays(2),
             'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['delivery_number' => 'DLV-2', 'sales_order_id' => $soId, 'status' => 'delivered',
             'scheduled_date' => now()->subDays(5)->toDateString(), 'delivered_at' => now()->subDays(2),
             'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // otdRate lives in PlantManagerDashboardService.
        $svc = app(PlantManagerDashboardService::class);
        $rate = (new \ReflectionClass($svc));
        $m = $rate->getMethod('otdRate');
        $m->setAccessible(true);

        $this->assertSame('50.0', $m->invoke($svc));
    }

    public function test_zone_utilization_uses_zone_name_and_current_quantity(): void
    {
        $whId = DB::table('warehouses')->insertGetId([
            'code' => 'WH1', 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $zoneId = DB::table('warehouse_zones')->insertGetId([
            'warehouse_id' => $whId, 'name' => 'Raw Materials', 'code' => 'A',
            'zone_type' => 'raw_materials', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 1 occupied (current_quantity > 0), 1 empty → 50%.
        DB::table('warehouse_locations')->insert([
            ['zone_id' => $zoneId, 'code' => 'A-1', 'is_active' => true, 'current_quantity' => 12.5,
             'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()],
            ['zone_id' => $zoneId, 'code' => 'A-2', 'is_active' => true, 'current_quantity' => 0,
             'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // warehouseZoneUtilization lives in WarehouseDashboardService.
        $svc = app(WarehouseDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('warehouseZoneUtilization');
        $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $this->assertCount(1, $rows);
        $this->assertSame('Raw Materials', $rows[0]['name']);
        $this->assertSame('A', $rows[0]['zone']);
        $this->assertSame(50, $rows[0]['percent']);
    }

    public function test_low_stock_alerts_use_available_quantity_in_one_query(): void
    {
        $catId = DB::table('item_categories')->insertGetId([
            'name' => 'Raw', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('items')->insertGetId([
            'code' => 'RC-001', 'name' => 'Resin C', 'category_id' => $catId,
            'item_type' => 'raw_material', 'unit_of_measure' => 'kg', 'standard_cost' => 0,
            'reorder_method' => 'manual', 'reorder_point' => 200, 'safety_stock' => 0,
            'minimum_order_quantity' => 0, 'lead_time_days' => 0, 'is_critical' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $whId = DB::table('warehouses')->insertGetId(['code' => 'WH1', 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $zoneId = DB::table('warehouse_zones')->insertGetId(['warehouse_id' => $whId, 'name' => 'A', 'code' => 'A', 'zone_type' => 'raw_materials', 'created_at' => now(), 'updated_at' => now()]);
        $locId = DB::table('warehouse_locations')->insertGetId(['zone_id' => $zoneId, 'code' => 'A-1', 'is_active' => true, 'current_quantity' => 0, 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
        // qty 150, reserved 60 → available 90 < reorder 200 → low.
        DB::table('stock_levels')->insert([
            'item_id' => $itemId, 'location_id' => $locId, 'quantity' => 150, 'reserved_quantity' => 60,
            'weighted_avg_cost' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // lowStockItemCount and warehouseLowStockAlerts live in WarehouseDashboardService.
        $svc    = app(WarehouseDashboardService::class);
        $ref    = new \ReflectionClass($svc);
        $countM = $ref->getMethod('lowStockItemCount'); $countM->setAccessible(true);
        $alertsM = $ref->getMethod('warehouseLowStockAlerts'); $alertsM->setAccessible(true);

        $this->assertSame(1, $countM->invoke($svc));
        $rows = $alertsM->invoke($svc);
        $this->assertCount(1, $rows);
        $this->assertSame('RC-001', $rows[0]['item_code']);
        $this->assertSame('90.00', $rows[0]['current_stock']);   // available, not gross 150
        $this->assertSame('110.00', $rows[0]['shortage']);        // 200 - 90
    }

    public function test_machine_availability_grid_marks_busy_days_from_planned_wo_window(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id' => 1, 'name' => 'Tester', 'slug' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id' => 1, 'name' => 'Test Creator', 'email' => 'creator@test.local',
            'password' => bcrypt('Password1!'), 'role_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-001', 'name' => '150T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $prodId = DB::table('products')->insertGetId([
            'part_number' => 'WB-001', 'name' => 'Wiper Bushing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // WO occupies today + tomorrow.
        DB::table('work_orders')->insert([
            'wo_number' => 'WO-1', 'product_id' => $prodId, 'machine_id' => $machineId,
            'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0, 'quantity_rejected' => 0,
            'scrap_rate' => 0, 'planned_start' => now()->startOfDay(), 'planned_end' => now()->addDay()->endOfDay(),
            'status' => 'confirmed', 'priority' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // machineAvailabilityGrid lives in PpcDashboardService.
        $svc = app(PpcDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('machineAvailabilityGrid'); $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $today = now()->toDateString();
        $day3  = now()->addDays(3)->toDateString();
        $busyToday = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-001' && $r['date'] === $today);
        $freeDay3  = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-001' && $r['date'] === $day3);

        $this->assertSame('busy', $busyToday['status']);
        $this->assertSame('available', $freeDay3['status']);
    }

    public function test_production_gantt_includes_wo_number_for_occupied_cells(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id' => 1, 'name' => 'Tester', 'slug' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id' => 1, 'name' => 'Test Creator', 'email' => 'creator@test.local',
            'password' => bcrypt('Password1!'), 'role_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-002', 'name' => '150T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $prodId = DB::table('products')->insertGetId([
            'part_number' => 'P1', 'name' => 'Part', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_orders')->insert([
            'wo_number' => 'WO-202604-0006', 'product_id' => $prodId, 'machine_id' => $machineId,
            'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0, 'quantity_rejected' => 0,
            'scrap_rate' => 0, 'planned_start' => now()->startOfDay(), 'planned_end' => now()->endOfDay(),
            'status' => 'in_progress', 'priority' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // productionGantt lives in PpcDashboardService.
        $svc = app(PpcDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('productionGantt'); $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $today = now()->toDateString();
        $cell = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-002' && $r['day'] === $today);
        $this->assertSame('running', $cell['status']);
        $this->assertSame('WO-202604-0006', $cell['wo_number']);
    }

    public function test_alerts_return_itemized_rows_with_entity_refs(): void
    {
        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-003', 'name' => '200T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'breakdown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('machine_downtimes')->insert([
            'machine_id' => $machineId, 'start_time' => now()->subHour(), 'end_time' => null,
            'category' => 'breakdown', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // alerts lives in DashboardQueries trait — use PlantManagerDashboardService as the carrier.
        $svc = app(PlantManagerDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('alerts'); $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $breakdown = collect($rows)->first(fn ($r) => $r['kind'] === 'breakdown');
        $this->assertNotNull($breakdown);
        $this->assertSame('danger', $breakdown['severity']);
        $this->assertStringContainsString('IM-003', $breakdown['label']);
        $this->assertSame('machine', $breakdown['ref']);
        $this->assertNotNull($breakdown['ref_id']);
    }

    public function test_plant_manager_respects_range_param_for_revenue_window(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Tester',
            'slug'       => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Test Creator',
            'email'      => 'creator@test.local',
            'password'   => bcrypt('Password1!'),
            'role_id'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customers')->insertOrIgnore([
            'id'                 => 1,
            'name'               => 'Acme',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Second invoice: guaranteed in-month but NOT today.
        $secondDate = now()->startOfMonth()->addDays(1);
        if ($secondDate->toDateString() === now()->toDateString()) {
            $secondDate = now()->startOfMonth()->addDays(2);
        }

        DB::table('invoices')->insert([
            // Today
            ['invoice_number' => 'INV-1', 'customer_id' => 1, 'status' => 'finalized',
             'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
             'total_amount' => 1000, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
            // In month, not today
            ['invoice_number' => 'INV-2', 'customer_id' => 1, 'status' => 'finalized',
             'date' => $secondDate->toDateString(), 'due_date' => $secondDate->copy()->addDays(30)->toDateString(),
             'total_amount' => 5000, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = \App\Modules\Auth\Models\User::factory()->create();

        // plantManager is still callable on the facade for integration-style tests.
        $today = $this->service()->plantManager($user, 'today');
        $month = $this->service()->plantManager($user, 'month');

        $revToday = collect($today['kpis'])->firstWhere('label', 'Revenue · Today')['value'];
        $revMonth = collect($month['kpis'])->firstWhere('label', 'Revenue · Month')['value'];

        $this->assertSame('1000.00', $revToday);
        $this->assertSame('6000.00', $revMonth);
    }

    public function test_upcoming_deliveries_count_po_line_items(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id' => 1, 'name' => 'Tester', 'slug' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id' => 1, 'name' => 'Test Creator', 'email' => 'creator@test.local',
            'password' => bcrypt('Password1!'), 'role_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vendorId = DB::table('vendors')->insertGetId([
            'name' => 'Taiwan Plastics', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-202604-0015', 'vendor_id' => $vendorId, 'status' => 'sent',
            'date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = DB::table('item_categories')->insertGetId(['name' => 'Raw', 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('items')->insertGetId([
            'code' => 'RB-001', 'name' => 'Resin B', 'category_id' => $catId, 'item_type' => 'raw_material',
            'unit_of_measure' => 'kg', 'standard_cost' => 0, 'reorder_method' => 'manual', 'reorder_point' => 0,
            'safety_stock' => 0, 'minimum_order_quantity' => 0, 'lead_time_days' => 0, 'is_critical' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            ['purchase_order_id' => $poId, 'item_id' => $itemId, 'description' => 'Resin B', 'quantity' => 500,
             'unit_price' => 0, 'total' => 0, 'quantity_received' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['purchase_order_id' => $poId, 'item_id' => $itemId, 'description' => 'Resin B2', 'quantity' => 200,
             'unit_price' => 0, 'total' => 0, 'quantity_received' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // purchasingUpcomingDeliveries lives in PurchasingDashboardService.
        $svc = app(PurchasingDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('purchasingUpcomingDeliveries'); $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $this->assertSame('PO-202604-0015', $rows[0]['po_number']);
        $this->assertSame(2, $rows[0]['items_count']);
    }

    public function test_probation_alerts_finds_employee_whose_6mo_ends_within_30_days(): void
    {
        $deptId = DB::table('departments')->insertGetId(['name' => 'Prod', 'code' => 'PRD', 'created_at' => now(), 'updated_at' => now()]);
        $posId  = DB::table('positions')->insertGetId(['title' => 'Op', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employees')->insert([
            'employee_no' => 'OGM-P-1', 'first_name' => 'Ana', 'last_name' => 'Reyes',
            'birth_date' => '1995-05-10', 'gender' => 'female', 'civil_status' => 'single',
            'department_id' => $deptId, 'position_id' => $posId, 'employment_type' => 'probationary',
            'pay_type' => 'monthly', 'date_hired' => now()->subMonths(6)->addDays(15)->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // hrProbationAlerts lives in HrDashboardService.
        $svc = app(HrDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('hrProbationAlerts'); $m->setAccessible(true);
        $rows = $m->invoke($svc);

        $this->assertCount(1, $rows);
        $this->assertSame('OGM-P-1', $rows[0]['employee_no']);
    }

    public function test_calendar_events_lists_birthdays_in_current_month_sorted_by_day(): void
    {
        $deptId = DB::table('departments')->insertGetId(['name' => 'Prod2', 'code' => 'PRD2', 'created_at' => now(), 'updated_at' => now()]);
        $posId  = DB::table('positions')->insertGetId(['title' => 'Op2', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now()]);
        $month = (int) now()->format('m');
        $mk = function (string $no, int $day) use ($deptId, $posId, $month) {
            DB::table('employees')->insert([
                'employee_no' => $no, 'first_name' => $no, 'last_name' => '',
                'birth_date' => sprintf('1990-%02d-%02d', $month, $day),
                'gender' => 'male', 'civil_status' => 'single', 'department_id' => $deptId,
                'position_id' => $posId, 'employment_type' => 'regular', 'pay_type' => 'monthly',
                'date_hired' => '2024-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $mk('B-20', 20); $mk('B-05', 5);

        // hrCalendarEvents lives in HrDashboardService.
        $svc = app(HrDashboardService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('hrCalendarEvents'); $m->setAccessible(true);
        $events = $m->invoke($svc);

        $this->assertSame(2, $events['birthdays_count']);
        $this->assertSame('B-05', $events['birthdays'][0]['name']);
        $this->assertSame('B-20', $events['birthdays'][1]['name']);
    }
}
