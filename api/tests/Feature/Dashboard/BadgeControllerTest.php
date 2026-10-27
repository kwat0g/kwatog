<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Enums\ProfileUpdateStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\Training;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Carbon\Carbon;
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
        $this->assertTrue($user->hasPermission('payroll.view'));
        $this->assertFalse($user->hasPermission('payroll.periods.view'));

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        // Employee role lacks every approver permission, so the action-only
        // keys must be absent. The shared `approvals.board.view` permission
        // is granted to every seeded role, so `approvals` may be present
        // (with a count of 0).
        $this->assertArrayNotHasKey('purchase_requests', $resp);
        $this->assertArrayNotHasKey('payroll', $resp);
        $this->assertArrayNotHasKey('leaves',            $resp);
        $this->assertArrayNotHasKey('overtime',          $resp);
        $this->assertArrayNotHasKey('maintenance_wo',    $resp);
        $this->assertArrayNotHasKey('low_stock',         $resp);
        $this->assertArrayNotHasKey('ncrs',              $resp);
        $this->assertArrayNotHasKey('profile_requests',  $resp);
    }

    public function test_read_badges_match_the_permissions_that_open_their_destination_lists(): void
    {
        $vicePresident = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'vice_president')->value('id'),
        ]);
        $this->assertTrue($vicePresident->hasPermission('crm.complaints.view'));
        $this->assertFalse($vicePresident->hasPermission('crm.complaints.manage'));
        $this->actingAs($vicePresident, 'sanctum')
            ->getJson('/api/v1/crm/complaints')
            ->assertOk();
        $vicePresidentBadges = $this->actingAs($vicePresident, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('open_complaints', $vicePresidentBadges);

        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $this->assertTrue($warehouse->hasPermission('supply_chain.deliveries.view'));
        $this->assertFalse($warehouse->hasPermission('supply_chain.view'));
        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/supply-chain/deliveries')
            ->assertOk();
        $warehouseBadges = $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('deliveries', $warehouseBadges);

        $hrOfficer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
        $this->assertTrue($hrOfficer->hasPermission('payroll.periods.view'));
        $this->actingAs($hrOfficer, 'sanctum')
            ->getJson('/api/v1/payroll-periods')
            ->assertOk();
        $hrBadges = $this->actingAs($hrOfficer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('payroll', $hrBadges);
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

    public function test_badge_permissions_match_destination_list_permissions(): void
    {
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $employee = User::factory()->create(['role_id' => $employeeRole->id]);
        $this->assertTrue($employee->hasPermission('payroll.view'));
        $this->assertFalse($employee->hasPermission('payroll.periods.view'));
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/payroll-periods')
            ->assertForbidden();
        $employeeBadges = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('payroll', $employeeBadges);

        $periodReader = User::factory()->create(['role_id' => $employeeRole->id]);
        $this->grantPermission($periodReader, 'payroll.periods.view', $admin);
        $this->actingAs($periodReader, 'sanctum')
            ->getJson('/api/v1/payroll-periods')
            ->assertOk();
        $periodBadges = $this->actingAs($periodReader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('payroll', $periodBadges);

        $complaintReader = User::factory()->create([
            'role_id' => Role::where('slug', 'qc_inspector')->value('id'),
        ]);
        $this->assertTrue($complaintReader->hasPermission('crm.complaints.view'));
        $this->assertFalse($complaintReader->hasPermission('crm.complaints.manage'));
        $this->actingAs($complaintReader, 'sanctum')
            ->getJson('/api/v1/crm/complaints')
            ->assertOk();
        $complaintBadges = $this->actingAs($complaintReader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('open_complaints', $complaintBadges);

        $warehouse = User::factory()->create([
            'role_id' => Role::where('slug', 'warehouse_staff')->value('id'),
        ]);
        $this->assertTrue($warehouse->hasPermission('supply_chain.deliveries.view'));
        $this->assertFalse($warehouse->hasPermission('supply_chain.view'));
        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/supply-chain/deliveries')
            ->assertOk();
        $deliveryBadges = $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('deliveries', $deliveryBadges);
    }

    public function test_profile_request_badge_matches_the_hr_and_finance_review_queues(): void
    {
        $reviewerEmployee = Employee::factory()->create();
        $hrReviewer = User::factory()->create([
            'role_id' => Role::where('slug', 'department_head')->value('id'),
            'employee_id' => $reviewerEmployee->id,
        ]);
        $requester = User::factory()->create();
        $sameDepartment = Employee::factory()->create(['department_id' => $reviewerEmployee->department_id]);
        $otherDepartment = Employee::factory()->create();

        $createRequest = static fn (Employee $employee, ProfileUpdateStatus $status, bool $requiresFinance) => ProfileUpdateRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
            'status' => $status->value,
            'requires_finance' => $requiresFinance,
            'changes' => $requiresFinance
                ? ['bank_name' => 'Test Bank']
                : ['mobile_number' => '09170000000'],
        ]);

        $createRequest($sameDepartment, ProfileUpdateStatus::Pending, false);
        $createRequest($sameDepartment, ProfileUpdateStatus::Pending, true);
        $createRequest($sameDepartment, ProfileUpdateStatus::PendingFinance, true);
        $createRequest($sameDepartment, ProfileUpdateStatus::Approved, true);
        $createRequest($otherDepartment, ProfileUpdateStatus::Pending, false);

        $hrList = $this->actingAs($hrReviewer, 'sanctum')
            ->getJson('/api/v1/hr/profile-update-requests')
            ->assertOk();
        $hrBadge = $this->actingAs($hrReviewer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.profile_requests.count');
        $this->assertSame(2, $hrList->json('meta.total'));
        $this->assertSame($hrList->json('meta.total'), $hrBadge);

        $finance = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
        ]);
        $createRequest($sameDepartment, ProfileUpdateStatus::PendingFinance, true);
        $createRequest($otherDepartment, ProfileUpdateStatus::PendingFinance, true);
        $createRequest($sameDepartment, ProfileUpdateStatus::PendingFinance, false);
        $createRequest($sameDepartment, ProfileUpdateStatus::Pending, true);
        $createRequest($sameDepartment, ProfileUpdateStatus::Approved, true);

        $financeList = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/hr/profile-update-requests')
            ->assertOk();
        $financeBadge = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.profile_requests.count');
        $this->assertSame(3, $financeList->json('meta.total'));
        $this->assertSame($financeList->json('meta.total'), $financeBadge);
    }

    public function test_inquiries_badge_matches_the_new_inquiry_list_filter(): void
    {
        $customerService = User::factory()->create([
            'role_id' => Role::where('slug', 'customer_service_officer')->value('id'),
        ]);
        ContactInquiry::factory()->create(['status' => ContactInquiryStatus::New]);
        ContactInquiry::factory()->create(['status' => ContactInquiryStatus::InProgress]);
        ContactInquiry::factory()->create(['status' => ContactInquiryStatus::Closed]);

        $list = $this->actingAs($customerService, 'sanctum')
            ->getJson('/api/v1/crm/inquiries?status=new')
            ->assertOk();
        $badge = $this->actingAs($customerService, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.inquiries.count');

        $this->assertSame(1, $list->json('meta.total'));
        $this->assertSame($list->json('meta.total'), $badge);
    }

    public function test_widened_scope_badge_counts_track_recent_rows(): void
    {
        $admin = Role::where('slug', 'system_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $admin->id]);
        $complaintViewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
        ]);

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
            'assigned_to'      => $user->id,
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
        DB::table('customer_complaints')->insert([
            'complaint_number' => 'CMP-TEST-0003',
            'customer_id'      => $customer->id,
            'received_date'    => today()->toDateString(),
            'severity'         => 'medium',
            'status'           => 'investigating',
            'description'      => 'Complaint investigation in progress',
            'created_by'       => $user->id,
            'assigned_to'      => $complaintViewer->id,
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
        // `assignment_key` is derived by EmployeeTraining::booted()'s `creating`
        // hook, which a raw DB::table() insert bypasses — every row would
        // otherwise fall to the column default '__unscheduled__' and trip
        // uq_emp_training_assignment_key (employee_id, training_id,
        // assignment_key) on the second insert. Mirror the hook explicitly.
        //
        // The two completed/expiring rows also need DIFFERENT trainings: the
        // key for an unscheduled row is the '__unscheduled__' sentinel, so one
        // employee may hold only one unscheduled assignment per training. That
        // is the invariant migration 2026_08_25_233000 added on purpose when it
        // replaced the NULL-bearing uq_emp_training_scheduled (Postgres treats
        // NULLs as distinct, so the old index let both rows through).
        $employee = Employee::factory()->create();
        $training = Training::create(['name' => 'IATF Awareness']);
        $certTraining = Training::create(['name' => 'Forklift Certification']);
        DB::table('employee_trainings')->insert([
            // Scheduled inside the 14-day window → counts.
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'assignment_key' => today()->addDays(7)->toDateString(), 'scheduled_for' => today()->addDays(7)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            // Completed, expiring inside the 30-day window → counts.
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'assignment_key' => '__unscheduled__', 'scheduled_for' => null, 'completed_at' => today()->subDays(10)->toDateString(), 'expires_at' => today()->addDays(15)->toDateString(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            // Scheduled beyond 14 days → excluded.
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'assignment_key' => today()->addDays(40)->toDateString(), 'scheduled_for' => today()->addDays(40)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            // Expiring beyond 30 days → excluded.
            ['employee_id' => $employee->id, 'training_id' => $certTraining->id, 'assignment_key' => '__unscheduled__', 'scheduled_for' => null, 'completed_at' => today()->subDays(30)->toDateString(), 'expires_at' => today()->addDays(60)->toDateString(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            // Inside the window but cancelled → excluded by status.
            ['employee_id' => $employee->id, 'training_id' => $training->id, 'assignment_key' => today()->addDays(3)->toDateString(), 'scheduled_for' => today()->addDays(3)->toDateString(), 'completed_at' => null, 'expires_at' => null, 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()],
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

        $complaintList = $this->actingAs($complaintViewer, 'sanctum')
            ->getJson('/api/v1/crm/complaints?signal=open')
            ->assertOk();
        $complaintBadge = $this->actingAs($complaintViewer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.open_complaints.count');

        $this->assertSame(1, $complaintList->json('meta.total'));
        $this->assertSame($complaintList->json('meta.total'), $complaintBadge);
        $this->assertSame(['investigating'], $complaintList->json('data.*.status'));

        $unassignedViewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
        ]);
        $unassignedList = $this->actingAs($unassignedViewer, 'sanctum')
            ->getJson('/api/v1/crm/complaints?signal=open')
            ->assertOk();
        $unassignedBadge = $this->actingAs($unassignedViewer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.open_complaints.count');

        $this->assertSame(0, $unassignedList->json('meta.total'));
        $this->assertSame(0, $unassignedBadge);

        $readOnlyViewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'vice_president')->value('id'),
        ]);
        $this->assertTrue($readOnlyViewer->hasPermission('crm.complaints.view'));
        $this->assertFalse($readOnlyViewer->hasPermission('crm.complaints.manage'));
        $readOnlyList = $this->actingAs($readOnlyViewer, 'sanctum')
            ->getJson('/api/v1/crm/complaints?signal=open')
            ->assertOk();
        $readOnlyBadge = $this->actingAs($readOnlyViewer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.open_complaints.count');

        $this->assertSame(0, $readOnlyList->json('meta.total'));
        $this->assertSame(0, $readOnlyBadge);

        $qualityUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        $inspectionList = $this->actingAs($qualityUser, 'sanctum')
            ->getJson('/api/v1/quality/inspections?work_queue=to_inspect')
            ->assertOk();
        $inspectionBadge = $this->actingAs($qualityUser, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.pending_inspections.count');

        $this->assertSame(2, $inspectionList->json('meta.total'));
        $this->assertSame($inspectionList->json('meta.total'), $inspectionBadge);
        $this->assertEqualsCanonicalizing(['draft', 'in_progress'], $inspectionList->json('data.*.status'));
    }

    public function test_pending_grn_badge_matches_the_filtered_receipt_list(): void
    {
        $warehouseRole = Role::query()->where('slug', 'warehouse_staff')->firstOrFail();
        $warehouseUser = User::factory()->create(['role_id' => $warehouseRole->id]);
        $this->assertTrue($warehouseUser->hasPermission('inventory.view'));

        \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->count(2)->create();
        \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create(['status' => 'accepted']);

        $list = $this->actingAs($warehouseUser, 'sanctum')
            ->getJson('/api/v1/inventory/grn?status=pending_qc')
            ->assertOk();
        $badge = $this->actingAs($warehouseUser, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.pending_grn.count');

        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame($list->json('meta.total'), $badge);
        $this->assertSame(['pending_qc', 'pending_qc'], $list->json('data.*.status'));
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
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        $headEmployee = Employee::factory()->create(['department_id' => $departmentA->id]);
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $deptHead->id,
            'employee_id' => $headEmployee->id,
        ]);

        // Twenty-five visible records trigger danger severity.
        for ($i = 0; $i < 25; $i++) {
            $this->addPendingLeaveApproval($headEmployee, 'department_head');
        }
        // The badge must exclude records outside the board's row scope and unsupported types.
        $this->addPendingLeaveApproval(Employee::factory()->create(['department_id' => $departmentB->id]), 'department_head');
        ApprovalRecord::create([
            'approvable_type' => 'UnknownApprovalType',
            'approvable_id' => 999,
            'step_order' => 1,
            'role_slug' => 'department_head',
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now(),
        ]);

        $board = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/approvals/board')
            ->assertOk()
            ->json('data');

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('approvals', $resp);
        $this->assertSame(25, $board['summary']['my_action']);
        $this->assertSame($board['summary']['my_action'], $resp['approvals']['count']);
        $this->assertSame('danger', $resp['approvals']['severity']);
    }

    public function test_severity_warning_for_small_count(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $deptHead->id,
            'employee_id' => $employee->id,
        ]);
        $this->addPendingLeaveApproval($employee, 'department_head');

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $resp['approvals']['count']);
        $this->assertSame('warning', $resp['approvals']['severity']);
    }

    public function test_approval_board_and_badge_count_items_beyond_the_visible_page_limit(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);

        for ($i = 0; $i < 101; $i++) {
            $this->addPendingLeaveApproval($employee, 'department_head');
        }

        $board = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/approvals/board')
            ->assertOk()
            ->json('data');
        $badges = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertCount(100, $board['my_action']);
        $this->assertSame(101, $board['summary']['my_action']);
        $this->assertTrue($board['meta']['pending_truncated']);
        $this->assertSame(101, $badges['approvals']['count']);
    }

    public function test_purchase_request_badge_matches_filtered_list_and_scope(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $grantor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        UserPermissionOverride::create([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'purchasing.pr.approve')->value('id'),
            'type' => PermissionOverrideType::Grant,
            'granted_by' => $grantor->id,
            'reason' => 'Exercise department-scoped purchase-request approvals.',
        ]);
        $approver->flushPermissionsCache();
        $requester = User::factory()->create();

        $visible = $this->addPendingPurchaseRequest($requester, $department->id, 'department_head');
        $this->addPendingPurchaseRequest($requester, $otherDepartment->id, 'department_head');
        $this->addPendingPurchaseRequest($requester, $department->id, 'finance_officer');
        $this->addPendingPurchaseRequest($approver, $department->id, 'department_head');

        $list = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests?signal=awaiting_my_approval')
            ->assertOk();
        $badge = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.purchase_requests.count');
        $pendingCount = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests/pending-count')
            ->assertOk()
            ->json('data.count');

        $this->assertSame(1, $list->json('meta.total'));
        $this->assertSame([$visible->hash_id], $list->json('data.*.id'));
        $this->assertSame(1, $badge);
        $this->assertSame($badge, $pendingCount);
    }

    public function test_purchase_request_badge_and_filter_include_active_delegates(): void
    {
        $delegator = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $delegate = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        foreach (['purchasing.view', 'purchasing.pr.approve'] as $permissionSlug) {
            UserPermissionOverride::create([
                'user_id' => $delegate->id,
                'permission_id' => Permission::query()->where('slug', $permissionSlug)->value('id'),
                'type' => PermissionOverrideType::Grant,
                'granted_by' => $admin->id,
                'reason' => 'Allow verification of delegated purchase-request access.',
            ]);
        }
        $delegate->flushPermissionsCache();
        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $requester = User::factory()->create();
        $visible = $this->addPendingPurchaseRequest($requester, null, 'finance_officer');
        $list = $this->actingAs($delegate, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests?signal=awaiting_my_approval')
            ->assertOk();
        $badge = $this->actingAs($delegate, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.purchase_requests.count');

        $this->assertSame(1, $list->json('meta.total'));
        $this->assertSame([$visible->hash_id], $list->json('data.*.id'));
        $this->assertSame(1, $badge);
    }

    public function test_purchase_request_badge_is_zero_without_list_access(): void
    {
        $department = Department::factory()->create();
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        UserPermissionOverride::create([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'purchasing.view')->value('id'),
            'type' => PermissionOverrideType::Deny,
            'granted_by' => $admin->id,
            'reason' => 'Verify approval counts do not expose a queue without list access.',
        ]);
        UserPermissionOverride::create([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'purchasing.pr.approve')->value('id'),
            'type' => PermissionOverrideType::Grant,
            'granted_by' => $admin->id,
            'reason' => 'Keep approval authority while testing revoked list access.',
        ]);
        $approver->flushPermissionsCache();
        $this->assertTrue($approver->hasPermission('purchasing.pr.approve'));
        $this->assertFalse($approver->hasPermission('purchasing.view'));

        $this->addPendingPurchaseRequest(User::factory()->create(), $department->id, 'department_head');

        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests?signal=awaiting_my_approval')
            ->assertForbidden();
        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $badges = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('purchase_requests', $badges);
    }

    private function addPendingPurchaseRequest(User $requester, ?int $departmentId, string $roleSlug): PurchaseRequest
    {
        $request = PurchaseRequest::factory()->create([
            'requested_by' => $requester->id,
            'department_id' => $departmentId,
        ]);
        $request->forceFill(['status' => PurchaseRequestStatus::Pending])->save();
        ApprovalRecord::create([
            'approvable_type' => PurchaseRequest::class,
            'approvable_id' => $request->id,
            'step_order' => 1,
            'role_slug' => $roleSlug,
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now(),
        ]);

        return $request;
    }

    private function addPendingLeaveApproval(Employee $employee, string $roleSlug): void
    {
        $leave = LeaveRequest::factory()->create(['employee_id' => $employee->id]);

        ApprovalRecord::create([
            'approvable_type' => LeaveRequest::class,
            'approvable_id' => $leave->id,
            'step_order' => 1,
            'role_slug' => $roleSlug,
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now(),
        ]);
    }

    private function grantPermission(User $user, string $permissionSlug, User $admin): void
    {
        UserPermissionOverride::create([
            'user_id' => $user->id,
            'permission_id' => Permission::where('slug', $permissionSlug)->value('id'),
            'type' => PermissionOverrideType::Grant,
            'granted_by' => $admin->id,
            'reason' => 'Verify badge access follows the destination list permission.',
        ]);
        $user->flushPermissionsCache();
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

    public function test_budget_approval_badge_matches_the_current_approver_list(): void
    {
        $employee = Role::where('slug', 'employee')->firstOrFail();
        $emp = User::factory()->create(['role_id' => $employee->id]);
        $empResp = $this->actingAs($emp, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertArrayNotHasKey('pending_budgets', $empResp);
        $this->assertArrayNotHasKey('pending_transfers', $empResp);

        $vp = User::factory()->create([
            'role_id' => Role::where('slug', 'vice_president')->value('id'),
        ]);
        $vpResp = $this->actingAs($vp, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertSame(0, $vpResp['pending_budgets']['count']);
        $this->assertSame(0, $vpResp['pending_transfers']['count']);

        $maker = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
        ]);
        $fiscalYear = FiscalYear::factory()->create([
            'year' => 2026, 'status' => 'active',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);
        $budgets = app(\App\Modules\Accounting\Services\BudgetService::class);
        $draftOneLineBudget = function (string $name) use ($budgets, $fiscalYear): array {
            $account = Account::create([
                'code' => 'BG-'.substr(uniqid(), -6),
                'name' => $name,
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]);
            $budget = $budgets->create([
                'fiscal_year_id' => $fiscalYear->id,
                'department_id' => Department::factory()->create()->id,
                'budget_type' => 'operating',
                'name' => $name.' '.substr(uniqid(), -4),
            ], [['account_id' => $account->id, 'jan' => '500.00']]);

            return [$budget, $budget->lineItems()->firstOrFail()->fresh()];
        };

        // Two finance-submitted budgets are actionable by a vice president.
        [$stuckA] = $draftOneLineBudget('Badge submitted A');
        [$stuckB] = $draftOneLineBudget('Badge submitted B');
        $budgets->submit($stuckA, $maker->id);
        $budgets->submit($stuckB, $maker->id);

        // A second vice president cannot approve a budget submitted by that role.
        $vpMaker = User::factory()->create([
            'role_id' => Role::where('slug', 'vice_president')->value('id'),
        ]);
        [$sameRoleSubmission] = $draftOneLineBudget('Badge same-role submission');
        $budgets->submit($sameRoleSubmission, $vpMaker->id);

        // A second pair goes all the way active so a transfer can pend on it.
        [$liveA, $lineA] = $draftOneLineBudget('Badge live A');
        [$liveB, $lineB] = $draftOneLineBudget('Badge live B');
        $budgets->approve($budgets->submit($liveA, $maker->id)->fresh(), $vp->id);
        $budgets->approve($budgets->submit($liveB, $maker->id)->fresh(), $vp->id);
        app(\App\Modules\Accounting\Services\BudgetTransferService::class)->request([
            'from_line_item_id' => $lineA->id,
            'to_line_item_id' => $lineB->id,
            'month' => 'jan',
            'amount' => '50.00',
            'reason' => 'Badge probe transfer.',
        ], $maker->id);

        $tracked = $this->actingAs($vp, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertSame(2, $tracked['pending_budgets']['count']);
        $this->assertSame(1, $tracked['pending_transfers']['count']);
        $pendingBudgets = $this->actingAs($vp, 'sanctum')
            ->getJson('/api/v1/budgets?signal=awaiting_my_approval')
            ->assertOk();
        $this->assertSame($tracked['pending_budgets']['count'], $pendingBudgets->json('meta.total'));
        $this->assertSame(['submitted'], array_values(array_unique(array_column($pendingBudgets->json('data'), 'status'))));

        // Finance can review the vice-president's budget, but not budgets raised by its own role.
        $makerBadges = $this->actingAs($maker, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertSame(1, $makerBadges['pending_budgets']['count']);
        $makerBudgets = $this->actingAs($maker, 'sanctum')
            ->getJson('/api/v1/budgets?signal=awaiting_my_approval')
            ->assertOk();
        $this->assertSame($makerBadges['pending_budgets']['count'], $makerBudgets->json('meta.total'));
        $pendingTransfers = $this->actingAs($vp, 'sanctum')
            ->getJson('/api/v1/budget-transfers?status=pending&actionable=1')
            ->assertOk();
        $this->assertSame($tracked['pending_transfers']['count'], $pendingTransfers->json('meta.total'));
    }

    public function test_delegated_budget_badge_matches_the_filtered_list(): void
    {
        $maker = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
        ]);
        $delegator = User::factory()->create([
            'role_id' => Role::where('slug', 'vice_president')->value('id'),
        ]);
        $delegate = User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
        ]);
        $budget = Budget::factory()->create([
            'status' => 'submitted',
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);
        UserPermissionOverride::create([
            'user_id' => $delegate->id,
            'permission_id' => Permission::query()->where('slug', 'budgeting.view')->firstOrFail()->id,
            'type' => 'grant',
            'granted_by' => $delegator->id,
            'reason' => 'Budget approval badge parity test',
        ]);
        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'vice_president',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $delegate->flushPermissionsCache();

        $list = $this->actingAs($delegate, 'sanctum')
            ->getJson('/api/v1/budgets?signal=awaiting_my_approval')
            ->assertOk();
        $badges = $this->actingAs($delegate, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertTrue($delegate->fresh()->hasPermission('budgeting.view'));
        $this->assertFalse($delegate->fresh()->hasPermission('budgeting.approve'));
        $this->assertSame(1, $badges['pending_budgets']['count']);
        $this->assertSame($badges['pending_budgets']['count'], $list->json('meta.total'));
        $this->assertSame([$budget->hash_id], $list->json('data.*.id'));
    }

    public function test_overdue_bill_badge_matches_the_filtered_bill_list(): void
    {
        $now = Carbon::parse('2026-06-15 12:00:00', config('app.timezone'));
        Carbon::setTestNow($now);

        try {
            $finance = User::factory()->create([
                'role_id' => Role::where('slug', 'finance_officer')->value('id'),
            ]);
            Bill::factory()->create(['due_date' => today()->subDay()]);
            Bill::factory()->create([
                'status' => 'partial',
                'amount_paid' => 100,
                'balance' => 900,
                'due_date' => today()->subDay(),
            ]);
            $dueTodayBill = Bill::factory()->create(['due_date' => today()]);
            Bill::factory()->create(['status' => 'paid', 'due_date' => today()->subDay()]);
            Bill::factory()->create(['status' => 'draft', 'due_date' => today()->subDay()]);
            Bill::factory()->create(['status' => 'cancelled', 'due_date' => today()->subDay()]);

            $badgeCount = $this->actingAs($finance, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data.overdue_bills.count');
            $overdueList = $this->actingAs($finance, 'sanctum')
                ->getJson('/api/v1/bills?overdue=1')
                ->assertOk();

            $this->assertSame(2, $badgeCount);
            $this->assertSame($badgeCount, $overdueList->json('meta.total'));
            $this->assertSame(['partial', 'unpaid'], collect($overdueList->json('data'))->pluck('status')->sort()->values()->all());
            $this->assertSame([true, true], collect($overdueList->json('data'))->pluck('is_overdue')->all());

            $unpaidList = $this->actingAs($finance, 'sanctum')
                ->getJson('/api/v1/bills?status=unpaid')
                ->assertOk();
            $dueTodayRow = collect($unpaidList->json('data'))->firstWhere('id', $dueTodayBill->hash_id);
            $this->assertNotNull($dueTodayRow);
            $this->assertFalse($dueTodayRow['is_overdue']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
