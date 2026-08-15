<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\ApprovalRecord;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Training;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Polish Task S2 — sidebar badge count system.
 *
 * Covers the unified /badges endpoint: auth gate, empty payload for low-trust
 * users, populated payload for approver roles, severity threshold, and the
 * 2026-08-08 widened scope (inquiries, complaints, inspections, GRN, MRB,
 * shipments, MRP plans, returns, invoices, bills).
 */
class BadgeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/dashboards/badges')->assertStatus(401);
    }

    public function test_employee_role_gets_no_action_keys(): void
    {
        $employee = Role::where('slug', 'employee')->firstOrFail();
        $user = User::factory()->create(['role_id' => $employee->id]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        // Employee role lacks every approver permission, so the action-only
        // keys must be absent. The shared `approvals.board.view` permission
        // is granted to every seeded role, so `approvals` may be present
        // (with a count of 0).
        $this->assertArrayNotHasKey('purchase_requests', $resp);
        $this->assertArrayNotHasKey('leaves',            $resp);
        $this->assertArrayNotHasKey('overtime',          $resp);
        $this->assertArrayNotHasKey('maintenance_wo',    $resp);
        $this->assertArrayNotHasKey('low_stock',         $resp);
        $this->assertArrayNotHasKey('ncrs',              $resp);
        $this->assertArrayNotHasKey('profile_requests',  $resp);
    }

    public function test_widened_scope_badges_are_permission_gated(): void
    {
        $employee = Role::where('slug', 'employee')->firstOrFail();
        $emp = User::factory()->create(['role_id' => $employee->id]);

        $empResp = $this->actingAs($emp, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        foreach ($this->newScopeKeys() as $key) {
            $this->assertArrayNotHasKey($key, $empResp, "employee must not see {$key}");
        }

        // system_admin short-circuits hasPermission() → sees every badge.
        $admin = Role::where('slug', 'system_admin')->firstOrFail();
        $sys = User::factory()->create(['role_id' => $admin->id]);

        $sysResp = $this->actingAs($sys, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        foreach ($this->newScopeKeys() as $key) {
            $this->assertArrayHasKey($key, $sysResp, "system_admin must see {$key}");
            $this->assertSame(0, $sysResp[$key]['count']);
            $this->assertIsString($sysResp[$key]['label']);
            $this->assertIsString($sysResp[$key]['description']);
        }
    }

    public function test_widened_scope_badge_counts_track_recent_rows(): void
    {
        $admin = Role::where('slug', 'system_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $admin->id]);

        // ── Inquiries (no FK chain needed; inquiry_no is service-assigned) ─
        DB::table('contact_inquiries')->insert([
            ['inquiry_no' => 'QR-TEST-0001', 'full_name' => 'Alice', 'email' => 'a@x.test', 'message' => 'hello', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
            ['inquiry_no' => 'QR-TEST-0002', 'full_name' => 'Bob',   'email' => 'b@x.test', 'message' => 'hi',    'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
            ['inquiry_no' => 'QR-TEST-0003', 'full_name' => 'Cara',  'email' => 'c@x.test', 'message' => 'old',  'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Complaints (customer + creator required) ───────────────────
        $customer = Customer::factory()->create();
        DB::table('customer_complaints')->insert([
            'complaint_number' => 'CMP-TEST-0001',
            'customer_id'      => $customer->id,
            'received_date'    => today()->toDateString(),
            'severity'         => 'high',
            'status'           => 'open',
            'description'      => 'Customer complaint',
            'created_by'       => $user->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        DB::table('customer_complaints')->insert([
            'complaint_number' => 'CMP-TEST-0002',
            'customer_id'      => $customer->id,
            'received_date'    => today()->toDateString(),
            'severity'         => 'medium',
            'status'           => 'closed',
            'description'      => 'Old complaint',
            'created_by'       => $user->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // ── Inspections (product required) ─────────────────────────────
        $product = Product::factory()->create();
        DB::table('inspections')->insert([
            'inspection_number' => 'INS-TEST-0001', 'stage' => 'incoming', 'status' => 'draft',
            'product_id' => $product->id, 'batch_quantity' => 100, 'sample_size' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inspections')->insert([
            'inspection_number' => 'INS-TEST-0002', 'stage' => 'outgoing', 'status' => 'in_progress',
            'product_id' => $product->id, 'batch_quantity' => 50, 'sample_size' => 8,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inspections')->insert([
            'inspection_number' => 'INS-TEST-0003', 'stage' => 'incoming', 'status' => 'passed',
            'product_id' => $product->id, 'batch_quantity' => 10, 'sample_size' => 3,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── GRNs (factory builds PO + vendor) ──────────────────────────
        \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create();                    // pending_qc
        \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create(['status' => 'accepted']);

        // ── MRB holds (item + two locations + holder) ──────────────────
        $item = Item::factory()->create();
        $locA = WarehouseLocation::factory()->create();
        $locB = WarehouseLocation::factory()->create();
        DB::table('material_review_records')->insert([
            'mrb_number'            => 'MRB-TEST-0001',
            'item_id'               => $item->id,
            'quantity'              => 5,
            'source_location_id'    => $locA->id,
            'quarantine_location_id'=> $locB->id,
            'status'                => 'held',
            'held_by'               => $user->id,
            'held_at'               => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // ── Shipments (PO + creator required) ──────────────────────────
        $po = PurchaseOrder::factory()->create();
        DB::table('shipments')->insert([
            'shipment_number'   => 'SHP-TEST-0001',
            'purchase_order_id' => $po->id,
            'status'            => 'in_transit',
            'created_by'        => $user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        DB::table('shipments')->insert([
            'shipment_number'   => 'SHP-TEST-0002',
            'purchase_order_id' => $po->id,
            'status'            => 'received',
            'created_by'        => $user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // ── MRP plans (SO + generator required) ────────────────────────
        // Migration 2026_08_15_121000 allows only one active plan per sales
        // order, so the negative case needs its own SO. It must stay 'active':
        // the badge counts active plans WITH shortages, and this row is what
        // proves the discriminator is shortages_found rather than status.
        $so = SalesOrder::factory()->create();
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0001',
            'sales_order_id' => $so->id,
            'status'         => 'active',
            'shortages_found'=> 3,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $soWithoutShortages = SalesOrder::factory()->create();
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0002',
            'sales_order_id' => $soWithoutShortages->id,
            'status'         => 'active',
            'shortages_found'=> 0,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // ── Returns (RMA) ──────────────────────────────────────────────
        DB::table('return_requests')->insert([
            'rma_number' => 'RMA-TEST-0001', 'type' => 'customer_return', 'status' => 'pending_approval',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('return_requests')->insert([
            'rma_number' => 'RMA-TEST-0002', 'type' => 'supplier_return', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── Job postings (department + creator required) ──────────────
        $dept = Department::factory()->create();
        DB::table('job_postings')->insert([
            ['posting_number' => 'JP-TEST-0001', 'department_id' => $dept->id, 'title' => 'Operator', 'description' => 'x', 'requirements' => 'y', 'employment_type' => 'regular', 'status' => 'open', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['posting_number' => 'JP-TEST-0002', 'department_id' => $dept->id, 'title' => 'QC Inspector', 'description' => 'x', 'requirements' => 'y', 'employment_type' => 'regular', 'status' => 'open', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['posting_number' => 'JP-TEST-0003', 'department_id' => $dept->id, 'title' => 'Clerk', 'description' => 'x', 'requirements' => 'y', 'employment_type' => 'regular', 'status' => 'closed', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Employee trainings (upcoming / expiring soon) ──────────────
        $employee = Employee::factory()->create();
        $training = Training::create(['name' => 'IATF Awareness']);
        DB::table('employee_trainings')->insert([
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'scheduled_for' => today()->addDays(7)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'scheduled_for' => null, 'completed_at' => today()->subDays(10)->toDateString(), 'expires_at' => today()->addDays(15)->toDateString(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'scheduled_for' => today()->addDays(40)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'scheduled_for' => null, 'completed_at' => today()->subDays(30)->toDateString(), 'expires_at' => today()->addDays(60)->toDateString(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'scheduled_for' => today()->addDays(3)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Invoices / Bills (factories) ───────────────────────────────
        Invoice::factory()->count(2)->create();                                   // draft
        Invoice::factory()->create(['status' => 'finalized']);
        Bill::factory()->create(['due_date' => today()->subDays(5)]);             // overdue
        Bill::factory()->create(['due_date' => today()->addDays(5)]);             // not overdue
        Bill::factory()->create(['status' => 'paid', 'due_date' => today()->subDays(9)]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $resp['inquiries']['count']);
        $this->assertSame(1, $resp['open_complaints']['count']);
        $this->assertSame(2, $resp['pending_inspections']['count']);
        $this->assertSame(1, $resp['pending_grn']['count']);
        $this->assertSame(1, $resp['mrb_holds']['count']);
        $this->assertSame(1, $resp['shipments']['count']);
        $this->assertSame(1, $resp['mrp_plans']['count']);
        $this->assertSame(1, $resp['pending_returns']['count']);
        $this->assertSame(2, $resp['draft_invoices']['count']);
        $this->assertSame(1, $resp['overdue_bills']['count']);
        $this->assertSame(2, $resp['open_postings']['count']);
        $this->assertSame(2, $resp['training_upcoming']['count']);
    }

    public function test_per_badge_severity_override_beats_global_threshold(): void
    {
        $admin = Role::where('slug', 'system_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $admin->id]);

        // 3 overdue bills: globally 3 <= danger(20) → 'warning'. The override
        // escalates overdue_bills to red at 2+, while draft_invoices (also 3)
        // must stay on the global scale.
        Bill::factory()->count(3)->create(['due_date' => today()->subDays(5)]);
        Invoice::factory()->count(3)->create(); // draft

        app(\App\Common\Services\SettingsService::class)
            ->set('dashboard.badges.overrides.overdue_bills.danger', 2);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame('danger', $resp['overdue_bills']['severity']);
        $this->assertSame(3, $resp['overdue_bills']['count']);
        $this->assertSame('warning', $resp['draft_invoices']['severity']);
    }

    public function test_department_head_sees_pending_approvals_with_severity(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        // 25 pending records routed to this role → severity must be 'danger'.
        for ($i = 0; $i < 25; $i++) {
            ApprovalRecord::create([
                'approvable_type' => 'X',
                'approvable_id'   => $i + 1,
                'step_order'      => 1,
                'role_slug'       => 'department_head',
                'action'          => 'pending',
                'created_at'      => now(),
            ]);
        }

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('approvals', $resp);
        $this->assertSame(25, $resp['approvals']['count']);
        $this->assertSame('danger', $resp['approvals']['severity']);
    }

    public function test_severity_warning_for_small_count(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        ApprovalRecord::create([
            'approvable_type' => 'X',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now(),
        ]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $resp['approvals']['count']);
        $this->assertSame('warning', $resp['approvals']['severity']);
    }

    public function test_zero_count_yields_neutral_severity(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $resp['approvals']['count']);
        $this->assertSame('neutral', $resp['approvals']['severity']);
    }

    public function test_severity_thresholds_come_from_settings(): void
    {
        app(\App\Common\Services\SettingsService::class)->set('dashboard.badges.danger_threshold', 2);
        app(\App\Common\Services\SettingsService::class)->set('dashboard.badges.warning_threshold', 0);

        $svc = app(\App\Modules\Dashboard\Services\BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('severity');
        $m->setAccessible(true);

        $this->assertSame('danger',  $m->invoke($svc, 3, 2, 0));   // > 2
        $this->assertSame('warning', $m->invoke($svc, 1, 2, 0));   // > 0, <= 2
        $this->assertSame('neutral', $m->invoke($svc, 0, 2, 0));
    }

    /** @return array<int, string> */
    private function newScopeKeys(): array
    {
        return [
            'inquiries', 'open_complaints', 'pending_inspections', 'pending_grn',
            'mrb_holds', 'shipments', 'mrp_plans', 'pending_returns',
            'draft_invoices', 'overdue_bills', 'training_upcoming', 'open_postings',
        ];
    }
}
