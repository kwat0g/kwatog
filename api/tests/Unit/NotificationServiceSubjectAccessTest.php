<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Mail\UserNotificationMail;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Common\Support\NotificationSubjectAccessRegistry;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceSubjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_subject_matches_detail_route_permission_and_row_scope(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        $view = Permission::firstOrCreate(
            ['slug' => 'purchasing.view'],
            ['name' => 'View purchasing', 'module' => 'purchasing'],
        );
        $approve = Permission::firstOrCreate(
            ['slug' => 'purchasing.po.approve'],
            ['name' => 'Approve purchase orders', 'module' => 'purchasing'],
        );
        $viewerRole = Role::firstOrCreate(['slug' => 'notification_po_viewer'], ['name' => 'Notification PO Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([$view->id]);
        $approverRole = Role::firstOrCreate(['slug' => 'notification_po_approver'], ['name' => 'Notification PO Approver']);
        $approverRole->permissions()->syncWithoutDetaching([$approve->id]);
        $owner = User::factory()->withRole($viewerRole->slug)->create();
        $outOfScope = User::factory()->withRole($viewerRole->slug)->create();
        $approverWithoutListPermission = User::factory()->withRole($approverRole->slug)->create();
        $withoutPermission = User::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create(['created_by' => $owner->id]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.purchase_order_subject_access',
                'subject_type' => PurchaseOrder::class,
                'subject_id' => $purchaseOrder->id,
                'user_ids' => [$outOfScope->id, $withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject(
            $purchaseOrder,
            [$owner, $approverWithoutListPermission, $outOfScope, $withoutPermission],
            'test.purchase_order_subject_access',
            ['title' => 'Purchase order update', 'message' => 'The order changed.'],
            emailEnabled: false,
        );

        foreach ([$owner, $approverWithoutListPermission] as $eligible) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $eligible->id,
                'type' => 'test.purchase_order_subject_access',
            ]);
            Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $eligible->id);
        }
        foreach ([$outOfScope, $withoutPermission] as $denied) {
            $this->assertDatabaseMissing('notifications', [
                'notifiable_id' => $denied->id,
                'type' => 'test.purchase_order_subject_access',
            ]);
            Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $denied->id);
        }

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.disabled_purchase_order_subject',
                'subject_type' => PurchaseOrder::class,
                'subject_id' => $purchaseOrder->id,
                'user_ids' => [$owner->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_purchase_order_subject'],
        );

        app(NotificationService::class)->sendForSubject(
            $purchaseOrder,
            $owner,
            'test.disabled_purchase_order_subject',
            ['title' => 'Purchase order update', 'message' => 'Purchasing is disabled.'],
            emailEnabled: false,
        );

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'test.disabled_purchase_order_subject',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_purchase_order_subject');
    }

    public function test_only_recipients_with_permission_and_record_scope_get_notice(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'purchasing.view'],
            ['name' => 'View purchasing', 'module' => 'purchasing'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_subject_viewer'],
            ['name' => 'Notification Subject Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $approvalPermission = Permission::firstOrCreate(
            ['slug' => 'purchasing.pr.approve'],
            ['name' => 'Approve purchase requests', 'module' => 'purchasing'],
        );
        $approvalRole = Role::firstOrCreate(
            ['slug' => 'notification_subject_pr_approver'],
            ['name' => 'Notification Subject PR Approver'],
        );
        $approvalRole->permissions()->syncWithoutDetaching([$approvalPermission->id]);
        $owner = User::factory()->withRole($role->slug)->create();
        $unrelated = User::factory()->withRole($role->slug)->create();
        $approvalOnly = User::factory()->withRole($approvalRole->slug)->create();
        $withoutPermission = User::factory()->create();
        $request = PurchaseRequest::factory()->create(['requested_by' => $owner->id]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.subject_access',
                'subject_type' => PurchaseRequest::class,
                'subject_id' => $request->id,
                'user_ids' => [$unrelated->id, $approvalOnly->id, $withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($request, [$owner, $unrelated, $approvalOnly, $withoutPermission], 'test.subject_access', [
            'title' => 'Purchase request update',
            'message' => 'The request changed.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'test.subject_access',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $unrelated->id,
            'type' => 'test.subject_access',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $approvalOnly->id,
            'type' => 'test.subject_access',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $withoutPermission->id,
            'type' => 'test.subject_access',
        ]);
        $data = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $owner->id)
            ->where('type', 'test.subject_access')
            ->value('data'), true);
        $this->assertSame(PurchaseRequest::class, $data['subject_type']);
        $this->assertSame((string) $request->id, $data['subject_id']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $owner->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $unrelated->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $approvalOnly->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);
    }

    public function test_purchase_request_subject_requires_purchasing_module_to_be_enabled(): void
    {
        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        $view = Permission::firstOrCreate(
            ['slug' => 'purchasing.view'],
            ['name' => 'View purchasing', 'module' => 'purchasing'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_subject_pr_module_viewer'],
            ['name' => 'Notification Subject PR Module Viewer'],
        );
        $role->permissions()->syncWithoutDetaching([$view->id]);
        $owner = User::factory()->withRole($role->slug)->create();
        $request = PurchaseRequest::factory()->create(['requested_by' => $owner->id]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_purchase_request_subject'],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.disabled_purchase_request_subject',
                'subject_type' => PurchaseRequest::class,
                'subject_id' => $request->id,
                'user_ids' => [$owner->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($request, $owner, 'test.disabled_purchase_request_subject', [
            'title' => 'Purchase request update',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'test.disabled_purchase_request_subject',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_delivery_subject_matches_internal_detail_route_and_module_flag(): void
    {
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
        $overview = Permission::firstOrCreate(
            ['slug' => 'supply_chain.view'],
            ['name' => 'View supply chain', 'module' => 'supply_chain'],
        );
        $deliveryView = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.view'],
            ['name' => 'View deliveries', 'module' => 'supply_chain'],
        );
        $deliveryCreate = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.create'],
            ['name' => 'Manage deliveries', 'module' => 'supply_chain'],
        );
        $overviewRole = Role::firstOrCreate(['slug' => 'notification_delivery_overview'], ['name' => 'Notification Delivery Overview']);
        $overviewRole->permissions()->syncWithoutDetaching([$overview->id]);
        $deliveryRole = Role::firstOrCreate(['slug' => 'notification_delivery_viewer'], ['name' => 'Notification Delivery Viewer']);
        $deliveryRole->permissions()->syncWithoutDetaching([$deliveryView->id]);
        $createOnlyRole = Role::firstOrCreate(['slug' => 'notification_delivery_creator'], ['name' => 'Notification Delivery Creator']);
        $createOnlyRole->permissions()->syncWithoutDetaching([$deliveryCreate->id]);
        $overviewUser = User::factory()->withRole($overviewRole->slug)->create();
        $deliveryViewer = User::factory()->withRole($deliveryRole->slug)->create();
        $createOnly = User::factory()->withRole($createOnlyRole->slug)->create();
        $withoutPermission = User::factory()->create();
        $order = SalesOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'created_by' => $overviewUser->id,
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'NTF-DEL-001',
            'sales_order_id' => $order->id,
            'status' => 'scheduled',
            'scheduled_date' => today(),
            'created_by' => $overviewUser->id,
        ]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.delivery_subject_access',
                'subject_type' => Delivery::class,
                'subject_id' => $delivery->id,
                'user_ids' => [$createOnly->id, $withoutPermission->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.disabled_delivery_subject',
                'subject_type' => Delivery::class,
                'subject_id' => $delivery->id,
                'user_ids' => [$overviewUser->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_delivery_subject'],
        );

        app(NotificationService::class)->sendForSubject(
            $delivery,
            [$overviewUser, $deliveryViewer, $createOnly, $withoutPermission],
            'test.delivery_subject_access',
            ['title' => 'Delivery update'],
            emailEnabled: false,
        );
        app(SettingsService::class)->set('modules.supply_chain', false, 'modules');
        app(NotificationService::class)->sendForSubject(
            $delivery,
            $overviewUser,
            'test.disabled_delivery_subject',
            ['title' => 'Delivery update'],
            emailEnabled: false,
        );

        foreach ([$overviewUser, $deliveryViewer] as $eligible) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $eligible->id,
                'type' => 'test.delivery_subject_access',
            ]);
            Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $eligible->id);
        }
        foreach ([$createOnly, $withoutPermission, $overviewUser] as $denied) {
            $this->assertDatabaseMissing('notifications', [
                'notifiable_id' => $denied->id,
                'type' => $denied === $overviewUser ? 'test.disabled_delivery_subject' : 'test.delivery_subject_access',
            ]);
        }
        $data = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $deliveryViewer->id)
            ->where('type', 'test.delivery_subject_access')
            ->value('data'), true);
        $this->assertSame(Delivery::class, $data['subject_type']);
        $this->assertSame((string) $delivery->id, $data['subject_id']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => in_array($event->userId, [$createOnly->id, $withoutPermission->id], true));
    }

    public function test_inventory_item_and_grn_subjects_require_inventory_detail_access(): void
    {
        app(SettingsService::class)->set('modules.inventory', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'inventory.view'],
            ['name' => 'View inventory', 'module' => 'inventory'],
        );
        $role = Role::firstOrCreate(['slug' => 'notification_inventory_viewer'], ['name' => 'Notification Inventory Viewer']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $viewer = User::factory()->withRole($role->slug)->create();
        $withoutPermission = User::factory()->create();
        $item = Item::factory()->create();
        $grn = GoodsReceiptNote::factory()->create();

        Event::fake([UserNotificationCreated::class]);
        app(NotificationService::class)->sendForSubject($item, [$viewer, $withoutPermission], 'test.item_subject_access', [
            'title' => 'Item update',
        ], emailEnabled: false);
        app(NotificationService::class)->sendForSubject($grn, [$viewer, $withoutPermission], 'test.grn_subject_access', [
            'title' => 'GRN update',
        ], emailEnabled: false);

        foreach (['test.item_subject_access', 'test.grn_subject_access'] as $type) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => $type]);
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withoutPermission->id, 'type' => $type]);
        }
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        app(NotificationService::class)->sendForSubject($item, $viewer, 'test.disabled_inventory_subject', [
            'title' => 'Item update',
        ], emailEnabled: false);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.disabled_inventory_subject']);
    }

    public function test_quality_subjects_require_their_detail_permission_and_enabled_module(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $inspectionPermission = Permission::firstOrCreate(
            ['slug' => 'quality.inspections.view'],
            ['name' => 'View inspections', 'module' => 'quality'],
        );
        $ncrPermission = Permission::firstOrCreate(
            ['slug' => 'quality.ncr.view'],
            ['name' => 'View NCRs', 'module' => 'quality'],
        );
        $inspectionRole = Role::firstOrCreate(['slug' => 'notification_inspection_viewer'], ['name' => 'Notification Inspection Viewer']);
        $inspectionRole->permissions()->syncWithoutDetaching([$inspectionPermission->id]);
        $ncrRole = Role::firstOrCreate(['slug' => 'notification_ncr_viewer'], ['name' => 'Notification NCR Viewer']);
        $ncrRole->permissions()->syncWithoutDetaching([$ncrPermission->id]);
        $inspectionViewer = User::factory()->withRole($inspectionRole->slug)->create();
        $ncrViewer = User::factory()->withRole($ncrRole->slug)->create();
        $withoutPermission = User::factory()->create();
        $product = Product::factory()->create();
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-202610-0001',
            'stage' => 'incoming',
            'status' => 'failed',
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 3,
        ]);
        $ncr = NonConformanceReport::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject(
            $inspection,
            [$inspectionViewer, $ncrViewer, $withoutPermission],
            'test.inspection_subject_access',
            ['title' => 'Inspection update'],
            emailEnabled: false,
        );
        app(NotificationService::class)->sendForSubject(
            $ncr,
            [$ncrViewer, $inspectionViewer, $withoutPermission],
            'test.ncr_subject_access',
            ['title' => 'NCR update'],
            emailEnabled: false,
        );

        foreach ([[$inspectionViewer, 'test.inspection_subject_access'], [$ncrViewer, 'test.ncr_subject_access']] as [$viewer, $type]) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => $type]);
        }
        foreach ([[$ncrViewer, 'test.inspection_subject_access'], [$inspectionViewer, 'test.ncr_subject_access'], [$withoutPermission, 'test.inspection_subject_access'], [$withoutPermission, 'test.ncr_subject_access']] as [$denied, $type]) {
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $denied->id, 'type' => $type]);
        }
        $inspectionData = json_decode((string) DB::table('notifications')->where('notifiable_id', $inspectionViewer->id)->where('type', 'test.inspection_subject_access')->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $ncrData = json_decode((string) DB::table('notifications')->where('notifiable_id', $ncrViewer->id)->where('type', 'test.ncr_subject_access')->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Inspection::class, $inspectionData['subject_type']);
        $this->assertSame((string) $inspection->id, $inspectionData['subject_id']);
        $this->assertSame(NonConformanceReport::class, $ncrData['subject_type']);
        $this->assertSame((string) $ncr->id, $ncrData['subject_id']);

        app(SettingsService::class)->set('modules.quality', false, 'modules');
        app(NotificationService::class)->sendForSubject(
            $inspection,
            $inspectionViewer,
            'test.disabled_quality_subject',
            ['title' => 'Inspection update'],
            emailEnabled: false,
        );
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inspectionViewer->id, 'type' => 'test.disabled_quality_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_quality_subject');
    }

    public function test_supplier_return_subject_respects_hidden_supplier_return_route_scope(): void
    {
        app(SettingsService::class)->set('modules.return_management', true, 'modules');
        $viewPermission = Permission::firstOrCreate(
            ['slug' => 'return_management.view'],
            ['name' => 'View return management', 'module' => 'return_management'],
        );
        $poCreatePermission = Permission::firstOrCreate(
            ['slug' => 'purchasing.po.create'],
            ['name' => 'Create purchase orders', 'module' => 'purchasing'],
        );
        $viewerRole = Role::firstOrCreate(['slug' => 'notification_return_viewer'], ['name' => 'Notification Return Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([$viewPermission->id, $poCreatePermission->id]);
        $customerServiceRole = Role::firstOrCreate(['slug' => 'customer_service_officer'], ['name' => 'Customer Service Officer']);
        $customerServiceRole->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $viewer = User::factory()->withRole($viewerRole->slug)->create();
        $customerServiceOfficer = User::factory()->withRole($customerServiceRole->slug)->create();
        $return = ReturnRequest::query()->create([
            'rma_number' => 'RMA-NOTICE-'.uniqid(),
            'type' => 'supplier_return',
            'status' => 'draft',
        ]);
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject(
            $return,
            [$viewer, $customerServiceOfficer],
            'test.supplier_return_subject_access',
            ['title' => 'Supplier return update'],
            emailEnabled: false,
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.supplier_return_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $customerServiceOfficer->id, 'type' => 'test.supplier_return_subject_access']);
        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $viewer->id)->where('type', 'test.supplier_return_subject_access')->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(ReturnRequest::class, $data['subject_type']);
        $this->assertSame((string) $return->id, $data['subject_id']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $customerServiceOfficer->id);
    }

    public function test_payroll_subjects_match_staff_and_owner_routes(): void
    {
        app(SettingsService::class)->set('modules.payroll', true, 'modules');
        $periodView = Permission::firstOrCreate(['slug' => 'payroll.periods.view'], ['name' => 'View payroll periods', 'module' => 'payroll']);
        $adjustmentView = Permission::firstOrCreate(['slug' => 'payroll.adjustments.view'], ['name' => 'View payroll adjustments', 'module' => 'payroll']);
        $payrollView = Permission::firstOrCreate(['slug' => 'payroll.view'], ['name' => 'View payslips', 'module' => 'payroll']);
        $periodRole = Role::firstOrCreate(['slug' => 'notification_payroll_period_viewer'], ['name' => 'Notification Payroll Period Viewer']);
        $periodRole->permissions()->syncWithoutDetaching([$periodView->id]);
        $adjustmentRole = Role::firstOrCreate(['slug' => 'notification_payroll_adjustment_viewer'], ['name' => 'Notification Payroll Adjustment Viewer']);
        $adjustmentRole->permissions()->syncWithoutDetaching([$adjustmentView->id]);
        $employeeRole = Role::firstOrCreate(['slug' => 'notification_payslip_viewer'], ['name' => 'Notification Payslip Viewer']);
        $employeeRole->permissions()->syncWithoutDetaching([$payrollView->id]);
        $periodViewer = User::factory()->withRole($periodRole->slug)->create();
        $adjustmentViewer = User::factory()->withRole($adjustmentRole->slug)->create();
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $employeeUser = User::factory()->withRole($employeeRole->slug)->create(['employee_id' => $employee->id]);
        $otherEmployeeUser = User::factory()->withRole($employeeRole->slug)->create(['employee_id' => $otherEmployee->id]);
        $withoutPermission = User::factory()->create();
        $period = PayrollPeriod::factory()->create();
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $adjustment = PayrollAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Missed overtime line',
            'created_by' => $employeeUser->id,
        ]);
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject($period, [$periodViewer, $withoutPermission], 'test.payroll_period_subject_access', ['title' => 'Payroll review'], emailEnabled: false);
        app(NotificationService::class)->sendForSubject($adjustment, [$adjustmentViewer, $withoutPermission], 'test.payroll_adjustment_subject_access', ['title' => 'Adjustment review'], emailEnabled: false);
        app(NotificationService::class)->sendForSubject($payroll, [$employeeUser, $otherEmployeeUser], 'test.payslip_subject_access', ['title' => 'Payslip ready'], emailEnabled: false);

        foreach ([[$periodViewer, 'test.payroll_period_subject_access'], [$adjustmentViewer, 'test.payroll_adjustment_subject_access'], [$employeeUser, 'test.payslip_subject_access']] as [$recipient, $type]) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id, 'type' => $type]);
        }
        foreach ([[$withoutPermission, 'test.payroll_period_subject_access'], [$withoutPermission, 'test.payroll_adjustment_subject_access'], [$otherEmployeeUser, 'test.payslip_subject_access']] as [$recipient, $type]) {
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $recipient->id, 'type' => $type]);
        }
        $payslipData = json_decode((string) DB::table('notifications')->where('notifiable_id', $employeeUser->id)->where('type', 'test.payslip_subject_access')->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Payroll::class, $payslipData['subject_type']);
        $this->assertSame((string) $payroll->id, $payslipData['subject_id']);

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        app(NotificationService::class)->sendForSubject($period, $periodViewer, 'test.disabled_payroll_subject', ['title' => 'Payroll review'], emailEnabled: false);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $periodViewer->id, 'type' => 'test.disabled_payroll_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_payroll_subject');
    }

    public function test_employee_training_subject_matches_hr_scope_and_self_service_routes(): void
    {
        app(SettingsService::class)->set('modules.hr', true, 'modules');
        $trainingView = Permission::firstOrCreate(['slug' => 'hr.employees.trainings.view'], ['name' => 'View employee training records', 'module' => 'hr']);
        $role = Role::firstOrCreate(['slug' => 'notification_training_viewer'], ['name' => 'Notification Training Viewer']);
        $role->permissions()->syncWithoutDetaching([$trainingView->id]);
        $department = Department::firstOrCreate(['code' => 'TRAINING-SCOPE'], ['name' => 'Training scope']);
        $otherDepartment = Department::firstOrCreate(['code' => 'TRAINING-OTHER'], ['name' => 'Other training scope']);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $inScopeEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $outOfScopeEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $inScopeViewer = User::factory()->withRole($role->slug)->create(['employee_id' => $inScopeEmployee->id]);
        $outOfScopeViewer = User::factory()->withRole($role->slug)->create(['employee_id' => $outOfScopeEmployee->id]);
        $withoutPermission = User::factory()->create();
        $training = Training::create(['name' => 'Forklift safety', 'validity_months' => 12, 'is_active' => true]);
        $record = EmployeeTraining::create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => now()->toDateString(),
            'completed_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
        ]);
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject($record, [$owner, $inScopeViewer, $outOfScopeViewer, $withoutPermission], 'test.training_subject_access', ['title' => 'Training expiry'], emailEnabled: false);

        foreach ([$owner, $inScopeViewer] as $recipient) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id, 'type' => 'test.training_subject_access']);
        }
        foreach ([$outOfScopeViewer, $withoutPermission] as $recipient) {
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $recipient->id, 'type' => 'test.training_subject_access']);
        }
        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $owner->id)->where('type', 'test.training_subject_access')->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(EmployeeTraining::class, $data['subject_type']);
        $this->assertSame((string) $record->id, $data['subject_id']);

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        app(NotificationService::class)->sendForSubject($record, $inScopeViewer, 'test.disabled_training_subject', ['title' => 'Training expiry'], emailEnabled: false);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inScopeViewer->id, 'type' => 'test.disabled_training_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_training_subject');
    }

    public function test_unregistered_subject_type_fails_closed(): void
    {
        $recipient = User::factory()->create();
        $subject = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: subject has no registered access rule.',
            [
                'type' => 'test.unregistered_subject',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
            ],
        );

        app(NotificationService::class)->sendForSubject($subject, $recipient, 'test.unregistered_subject', [
            'title' => 'Unknown subject',
        ]);

        $this->assertDatabaseMissing('notifications', ['type' => 'test.unregistered_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_soft_deleted_subject_fails_closed(): void
    {
        $recipient = User::factory()->create();
        $subject = PurchaseRequest::factory()->create();
        $subject->delete();
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: subject is unavailable.',
            [
                'type' => 'test.deleted_subject',
                'subject_type' => PurchaseRequest::class,
                'subject_id' => $subject->id,
            ],
        );

        app(NotificationService::class)->sendForSubject($subject, $recipient, 'test.deleted_subject', [
            'title' => 'Deleted subject',
        ]);

        $this->assertDatabaseMissing('notifications', ['type' => 'test.deleted_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_invoice_subject_requires_the_invoice_detail_permission(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'accounting.invoices.view'],
            ['name' => 'View invoices', 'module' => 'accounting'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_invoice_viewer'],
            ['name' => 'Notification Invoice Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $viewer = User::factory()->withRole($role->slug)->create();
        $withoutPermission = User::factory()->create();
        $invoice = Invoice::factory()->create();
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.invoice_subject_access',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'user_ids' => [$withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($invoice, [$viewer, $withoutPermission], 'test.invoice_subject_access', [
            'title' => 'Invoice update',
            'message' => 'Invoice changed.',
        ], emailEnabled: false);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $viewer->id,
            'type' => 'test.invoice_subject_access',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $withoutPermission->id,
            'type' => 'test.invoice_subject_access',
        ]);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $viewer->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);
    }

    public function test_invoice_subject_is_suppressed_when_accounting_is_disabled(): void
    {
        app(SettingsService::class)->set('modules.accounting', false, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'accounting.invoices.view'],
            ['name' => 'View invoices', 'module' => 'accounting'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_disabled_accounting_viewer'],
            ['name' => 'Notification Disabled Accounting Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $viewer = User::factory()->withRole($role->slug)->create();
        $invoice = Invoice::factory()->create();
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.disabled_invoice_subject',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'user_ids' => [$viewer->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_invoice_subject'],
        );

        app(NotificationService::class)->sendForSubject($invoice, $viewer, 'test.disabled_invoice_subject', [
            'title' => 'Invoice update',
            'message' => 'Invoice changed.',
        ], emailEnabled: false);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $viewer->id,
            'type' => 'test.disabled_invoice_subject',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_bill_subject_requires_the_bill_detail_permission(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'accounting.bills.view'],
            ['name' => 'View bills', 'module' => 'accounting'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_bill_viewer'],
            ['name' => 'Notification Bill Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $viewer = User::factory()->withRole($role->slug)->create();
        $withoutPermission = User::factory()->create();
        $bill = Bill::factory()->create(['created_by' => $viewer->id]);
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.bill_subject_access',
                'subject_type' => Bill::class,
                'subject_id' => $bill->id,
                'user_ids' => [$withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($bill, [$viewer, $withoutPermission], 'test.bill_subject_access', [
            'title' => 'Bill update',
            'message' => 'The bill changed.',
        ], emailEnabled: false);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.bill_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withoutPermission->id, 'type' => 'test.bill_subject_access']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $viewer->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);
    }

    public function test_sales_order_subject_requires_enabled_crm_and_list_permission(): void
    {
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'crm.sales_orders.view'],
            ['name' => 'View sales orders', 'module' => 'crm'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_sales_order_viewer'],
            ['name' => 'Notification Sales Order Viewer'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $viewer = User::factory()->withRole($role->slug)->create();
        $withoutPermission = User::factory()->create();
        $salesOrder = SalesOrder::factory()->create();
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.sales_order_subject_access',
                'subject_type' => SalesOrder::class,
                'subject_id' => $salesOrder->id,
                'user_ids' => [$withoutPermission->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.disabled_sales_order_subject',
                'subject_type' => SalesOrder::class,
                'subject_id' => $salesOrder->id,
                'user_ids' => [$viewer->id],
            ],
        );
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_sales_order_subject'],
        );

        app(NotificationService::class)->sendForSubject($salesOrder, [$viewer, $withoutPermission], 'test.sales_order_subject_access', [
            'title' => 'Sales order update',
            'message' => 'The sales order changed.',
        ], emailEnabled: false);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.sales_order_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withoutPermission->id, 'type' => 'test.sales_order_subject_access']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $viewer->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);

        app(SettingsService::class)->set('modules.crm', false, 'modules');
        app(NotificationService::class)->sendForSubject($salesOrder, $viewer, 'test.disabled_sales_order_subject', [
            'title' => 'Sales order update',
        ], emailEnabled: false);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.disabled_sales_order_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_sales_order_subject');
    }

    public function test_subject_gated_email_failure_rechecks_sales_order_access(): void
    {
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'crm.sales_orders.view'],
            ['name' => 'View sales orders', 'module' => 'crm'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_sales_order_email_viewer'],
            ['name' => 'Notification Sales Order Email Viewer'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $viewer = User::factory()->withRole($role->slug)->create(['email' => 'sales@example.test']);
        $salesOrder = SalesOrder::factory()->create();
        DB::table('notification_preferences')->insert([
            'user_id' => $viewer->id,
            'notification_type' => 'test.sales_order_email',
            'channel' => 'email',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Event::fake([UserNotificationCreated::class]);
        Mail::fake();

        app(NotificationService::class)->sendForSubject($salesOrder, $viewer, 'test.sales_order_email', [
            'title' => 'Sales order update',
            'message' => 'The sales order changed.',
            'link_to' => '/crm/sales-orders/'.$salesOrder->hash_id,
        ]);

        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) use ($salesOrder): bool {
            self::assertTrue($mail->failureSubject?->is($salesOrder) ?? false);
            app(SettingsService::class)->set('modules.crm', false, 'modules');
            $mail->failed(new \RuntimeException('Email provider failed.'));

            return true;
        });

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $viewer->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    public function test_stock_adjustment_subject_requires_enabled_module_and_list_permission(): void
    {
        app(SettingsService::class)->set('modules.inventory', true, 'modules');
        $makerPermission = Permission::firstOrCreate(
            ['slug' => 'inventory.adjust'],
            ['name' => 'Adjust stock', 'module' => 'inventory'],
        );
        $approverPermission = Permission::firstOrCreate(
            ['slug' => 'inventory.adjust.approve'],
            ['name' => 'Approve stock adjustments', 'module' => 'inventory'],
        );
        $makerRole = Role::firstOrCreate(['slug' => 'notification_stock_maker'], ['name' => 'Notification Stock Maker']);
        $makerRole->permissions()->syncWithoutDetaching([$makerPermission->id]);
        $approverRole = Role::firstOrCreate(['slug' => 'notification_stock_approver'], ['name' => 'Notification Stock Approver']);
        $approverRole->permissions()->syncWithoutDetaching([$approverPermission->id]);
        $maker = User::factory()->withRole($makerRole->slug)->create();
        $approver = User::factory()->withRole($approverRole->slug)->create();
        $withoutPermission = User::factory()->create();
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $adjustment = StockAdjustment::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'direction' => 'in',
            'quantity' => '1.000',
            'unit_cost' => '1.0000',
            'value' => '1.00',
            'requested_by' => $maker->id,
        ]);
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject(
            $adjustment,
            [$maker, $approver, $withoutPermission],
            'test.stock_adjustment_subject_access',
            ['title' => 'Stock adjustment', 'message' => 'The adjustment changed.'],
            emailEnabled: false,
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $maker->id, 'type' => 'test.stock_adjustment_subject_access']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $approver->id, 'type' => 'test.stock_adjustment_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withoutPermission->id, 'type' => 'test.stock_adjustment_subject_access']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $maker->id);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $approver->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        app(NotificationService::class)->sendForSubject(
            $adjustment,
            $maker,
            'test.disabled_stock_adjustment_subject',
            ['title' => 'Stock adjustment', 'message' => 'The inventory module is disabled.'],
            emailEnabled: false,
        );

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $maker->id, 'type' => 'test.disabled_stock_adjustment_subject']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->type === 'test.disabled_stock_adjustment_subject');
    }

    public function test_supplier_listing_subject_requires_enabled_module_and_list_permission(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        $viewPermission = Permission::firstOrCreate(
            ['slug' => 'purchasing.supplier_listings.view'],
            ['name' => 'View supplier item listings', 'module' => 'purchasing'],
        );
        $reviewPermission = Permission::firstOrCreate(
            ['slug' => 'purchasing.supplier_listings.review'],
            ['name' => 'Review supplier item listings', 'module' => 'purchasing'],
        );
        $viewerRole = Role::firstOrCreate(['slug' => 'notification_listing_viewer'], ['name' => 'Notification Listing Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $reviewOnlyRole = Role::firstOrCreate(['slug' => 'notification_listing_reviewer'], ['name' => 'Notification Listing Reviewer']);
        $reviewOnlyRole->permissions()->syncWithoutDetaching([$reviewPermission->id]);
        $viewer = User::factory()->withRole($viewerRole->slug)->create();
        $reviewOnly = User::factory()->withRole($reviewOnlyRole->slug)->create();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();
        $listing = new SupplierItemListing([
            'vendor_id' => $vendor->id,
            'item_id' => $item->id,
            'price' => '100.00',
            'submitted_at' => now(),
        ]);
        $listing->save();
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject(
            $listing,
            [$viewer, $reviewOnly],
            'test.supplier_listing_subject_access',
            ['title' => 'Supplier listing', 'message' => 'A supplier listing changed.'],
            emailEnabled: false,
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.supplier_listing_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $reviewOnly->id, 'type' => 'test.supplier_listing_subject_access']);
        $notificationData = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $viewer->id)
            ->where('type', 'test.supplier_listing_subject_access')
            ->value('data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(SupplierItemListing::class, $notificationData['subject_type']);
        $this->assertSame((string) $listing->id, $notificationData['subject_id']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $viewer->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $reviewOnly->id);

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        app(NotificationService::class)->sendForSubject(
            $listing,
            $viewer,
            'test.disabled_supplier_listing_subject',
            ['title' => 'Supplier listing', 'message' => 'The Purchasing module is disabled.'],
            emailEnabled: false,
        );

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.disabled_supplier_listing_subject']);
    }

    public function test_loan_subject_allows_the_owner_route_and_scoped_internal_readers(): void
    {
        app(SettingsService::class)->set('modules.hr', true, 'modules');
        app(SettingsService::class)->set('modules.loans', true, 'modules');
        $loanView = Permission::firstOrCreate(
            ['slug' => 'loans.view'],
            ['name' => 'View loans', 'module' => 'loans'],
        );
        $financeRole = Role::firstOrCreate(['slug' => 'finance_officer'], ['name' => 'Finance Officer']);
        $financeRole->permissions()->syncWithoutDetaching([$loanView->id]);
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $owner = User::factory()->withRole('employee')->create(['employee_id' => $employee->id]);
        $other = User::factory()->withRole('employee')->create(['employee_id' => $otherEmployee->id]);
        $finance = User::factory()->withRole($financeRole->slug)->create();
        $loan = EmployeeLoan::factory()->create(['employee_id' => $employee->id]);
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->sendForSubject(
            $loan,
            [$owner, $finance, $other],
            'test.loan_subject_access',
            ['title' => 'Loan update', 'message' => 'The loan changed.'],
            emailEnabled: false,
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'type' => 'test.loan_subject_access']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $finance->id, 'type' => 'test.loan_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $other->id, 'type' => 'test.loan_subject_access']);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $owner->id);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $finance->id);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $other->id);

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        app(SettingsService::class)->set('modules.loans', false, 'modules');
        app(NotificationService::class)->sendForSubject(
            $loan,
            [$owner, $finance],
            'test.disabled_loan_subject',
            ['title' => 'Loan update', 'message' => 'Both loan routes are disabled.'],
            emailEnabled: false,
        );

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $owner->id, 'type' => 'test.disabled_loan_subject']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $finance->id, 'type' => 'test.disabled_loan_subject']);
    }

    public function test_budget_subject_allows_only_viewers_and_distinct_approvers(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');
        $settings->set('modules.budgeting', true, 'modules');
        $approvalPermission = Permission::firstOrCreate(
            ['slug' => 'budgeting.approve'],
            ['name' => 'Approve budgets', 'module' => 'budgeting'],
        );
        $viewPermission = Permission::firstOrCreate(
            ['slug' => 'budgeting.view'],
            ['name' => 'View budgets', 'module' => 'budgeting'],
        );
        $makerRole = Role::firstOrCreate(['slug' => 'notification_budget_maker'], ['name' => 'Notification Budget Maker']);
        $makerRole->permissions()->attach($approvalPermission->id);
        $checkerRole = Role::firstOrCreate(['slug' => 'notification_budget_checker'], ['name' => 'Notification Budget Checker']);
        $checkerRole->permissions()->attach($approvalPermission->id);
        $viewerRole = Role::firstOrCreate(['slug' => 'notification_budget_viewer'], ['name' => 'Notification Budget Viewer']);
        $viewerRole->permissions()->attach($viewPermission->id);
        $submitter = User::factory()->withRole($makerRole->slug)->create();
        $sameRolePeer = User::factory()->withRole($makerRole->slug)->create();
        $approver = User::factory()->withRole($checkerRole->slug)->create();
        $viewer = User::factory()->withRole($viewerRole->slug)->create();
        $budget = Budget::factory()->create(['status' => 'submitted', 'submitted_by' => $submitter->id]);
        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($sameRolePeer, $submitter): bool {
            $ids = $context['user_ids'] ?? [];
            sort($ids);
            $expectedIds = [$sameRolePeer->id, $submitter->id];
            sort($expectedIds);

            return $message === 'Notification recipients lack subject access; delivery suppressed.'
                && ($context['type'] ?? null) === 'test.budget_subject_access'
                && $ids === $expectedIds;
        });
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($viewer): bool {
            return $message === 'Notification recipients lack subject access; delivery suppressed.'
                && ($context['type'] ?? null) === 'test.disabled_budget_subject'
                && ($context['user_ids'] ?? []) === [$viewer->id];
        });
        Log::shouldReceive('warning')->once()->with(
            'Notification delivery skipped: no eligible recipients.',
            ['type' => 'test.disabled_budget_subject'],
        );

        app(NotificationService::class)->sendForSubject($budget, [$approver, $viewer, $sameRolePeer, $submitter], 'test.budget_subject_access', [
            'title' => 'Budget needs review',
            'message' => 'A budget was submitted.',
        ], emailEnabled: false);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $approver->id, 'type' => 'test.budget_subject_access']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.budget_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $sameRolePeer->id, 'type' => 'test.budget_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $submitter->id, 'type' => 'test.budget_subject_access']);

        $settings->set('modules.budgeting', false, 'modules');
        app(NotificationService::class)->sendForSubject($budget, $viewer, 'test.disabled_budget_subject', [
            'title' => 'Budget update',
        ], emailEnabled: false);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.disabled_budget_subject']);
    }

    public function test_subject_gated_email_failure_fallback_stays_in_app(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'accounting.invoices.view'],
            ['name' => 'View invoices', 'module' => 'accounting'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_invoice_failure_viewer'],
            ['name' => 'Notification Invoice Failure Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $viewer = User::factory()->withRole($role->slug)->create();
        $invoice = Invoice::factory()->create();
        DB::table('notification_preferences')->insert([
            'user_id' => $viewer->id,
            'notification_type' => 'email.delivery_failed',
            'channel' => 'email',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Event::fake([UserNotificationCreated::class]);
        Mail::fake();

        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            [$viewer->id],
            'Customer AR reminder',
            'The reminder failed.',
            ['link_to' => '/accounting/invoices/'.$invoice->hash_id],
            $invoice,
        );
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'accounting.invoices.view',
            'Customer invoice delivery',
            'The invoice email failed.',
            ['link_to' => '/accounting/invoices/'.$invoice->hash_id],
            subject: $invoice,
        );

        $this->assertSame(2, DB::table('notifications')
            ->where('notifiable_id', $viewer->id)
            ->where('type', 'email.delivery_failed')
            ->count());
        Mail::assertNothingQueued();
        Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $viewer->id);
    }

    public function test_work_order_subject_allows_internal_viewers_and_assigned_history_only(): void
    {
        app(SettingsService::class)->set('modules.production', true, 'modules');
        $permissions = collect(['production.view', 'production.work_orders.view'])->map(fn (string $slug) => Permission::firstOrCreate(
            ['slug' => $slug], ['name' => $slug, 'module' => 'production'],
        ));
        $role = Role::firstOrCreate(['slug' => 'notification_work_order_viewer'], ['name' => 'Notification Work Order Viewer']);
        $role->permissions()->sync($permissions->pluck('id'));
        $viewer = User::factory()->withRole($role->slug)->create();
        $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $worker = User::factory()->withRole('employee')->create(['employee_id' => $employee->id, 'is_active' => true]);
        $unassigned = User::factory()->withRole('employee')->create(['is_active' => true]);
        $wo = WorkOrder::factory()->create();
        $crew = WorkOrderCrew::query()->create([
            'work_order_id' => $wo->id,
            'recorder_employee_id' => $employee->id,
            'assigned_by' => $viewer->id,
            'reason' => 'Notification access test',
            'assigned_at' => now()->subDay(),
            'ended_at' => now(),
        ]);
        $crew->members()->create(['employee_id' => $employee->id, 'employee_snapshot' => ['name' => 'Production worker']]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.work_order_subject_access',
                'subject_type' => WorkOrder::class,
                'subject_id' => $wo->id,
                'user_ids' => [$unassigned->id],
            ],
        );
        app(NotificationService::class)->sendForSubject($wo, [$viewer, $worker, $unassigned], 'test.work_order_subject_access', [
            'title' => 'Work order update',
            'message' => 'Work order changed.',
            'link_to' => '/production/work-orders/'.$wo->hash_id,
        ], emailEnabled: false);

        foreach ([$viewer, $worker] as $recipient) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $recipient->id,
                'type' => 'test.work_order_subject_access',
            ]);
        }
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $unassigned->id,
            'type' => 'test.work_order_subject_access',
        ]);
        $this->assertTrue(NotificationSubjectAccessRegistry::userCanViewRecord($worker, $wo));

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($viewer, $wo));
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($worker, $wo));
    }

    public function test_maintenance_subjects_follow_the_linked_detail_and_handover_routes(): void
    {
        app(SettingsService::class)->set('modules.maintenance', true, 'modules');
        app(SettingsService::class)->set('modules.inventory', true, 'modules');
        $maintenanceView = Permission::firstOrCreate(['slug' => 'maintenance.view'], ['name' => 'View maintenance', 'module' => 'maintenance']);
        $assignedView = Permission::firstOrCreate(['slug' => 'maintenance.work.assigned'], ['name' => 'View assigned maintenance', 'module' => 'maintenance']);
        $pickingView = Permission::firstOrCreate(['slug' => 'inventory.picking.view'], ['name' => 'View material handovers', 'module' => 'inventory']);
        $viewerRole = Role::firstOrCreate(['slug' => 'notification_maintenance_viewer'], ['name' => 'Notification Maintenance Viewer']);
        $viewerRole->permissions()->sync([$maintenanceView->id]);
        $workerRole = Role::firstOrCreate(['slug' => 'notification_maintenance_worker'], ['name' => 'Notification Maintenance Worker']);
        $workerRole->permissions()->sync([$assignedView->id]);
        $pickerRole = Role::firstOrCreate(['slug' => 'notification_maintenance_picker'], ['name' => 'Notification Maintenance Picker']);
        $pickerRole->permissions()->sync([$pickingView->id]);
        $viewer = User::factory()->withRole($viewerRole->slug)->create(['is_active' => true]);
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $assignedWorker = User::factory()->withRole($workerRole->slug)->create(['employee_id' => $employee->id, 'is_active' => true]);
        $otherEmployee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $unassignedWorker = User::factory()->withRole($workerRole->slug)->create(['employee_id' => $otherEmployee->id, 'is_active' => true]);
        $picker = User::factory()->withRole($pickerRole->slug)->create(['is_active' => true]);
        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'NOTICE-'.uniqid(), 'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Access test', 'status' => 'in_progress',
            'assigned_to' => $employee->id, 'created_by' => $viewer->id,
        ]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.maintenance_subject_access',
                'subject_type' => MaintenanceWorkOrder::class,
                'subject_id' => $order->id,
                'user_ids' => [$unassignedWorker->id, $picker->id],
            ],
        );
        app(NotificationService::class)->sendForSubject($order, [$viewer, $assignedWorker, $unassignedWorker, $picker],
            'test.maintenance_subject_access', ['title' => 'Maintenance update'], emailEnabled: false);

        foreach ([$viewer, $assignedWorker] as $eligible) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $eligible->id, 'type' => 'test.maintenance_subject_access']);
            Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $eligible->id);
        }
        foreach ([$unassignedWorker, $picker] as $denied) {
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $denied->id, 'type' => 'test.maintenance_subject_access']);
            Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $denied->id);
        }
        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $assignedWorker->id)
            ->where('type', 'test.maintenance_subject_access')->value('data'), true);
        $this->assertSame(MaintenanceWorkOrder::class, $data['subject_type']);
        $this->assertSame((string) $order->id, $data['subject_id']);

        $partRequest = new MaintenancePartRequest;
        $this->assertTrue(NotificationSubjectAccessRegistry::supports(MaintenancePartRequest::class));
        $this->assertTrue(NotificationSubjectAccessRegistry::userCanViewRecord($picker, $partRequest));
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($unassignedWorker, $partRequest));
        app(SettingsService::class)->set('modules.maintenance', false, 'modules');
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($viewer, $order));
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($assignedWorker, $order));
        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($picker, $partRequest));
    }

    public function test_rfq_subject_follows_the_existing_record_visibility_policy(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        $permission = Permission::firstOrCreate(['slug' => 'purchasing.rfq.view'], ['name' => 'View supplier RFQs', 'module' => 'purchasing']);
        $role = Role::firstOrCreate(['slug' => 'notification_rfq_viewer'], ['name' => 'Notification RFQ Viewer']);
        $role->permissions()->sync([$permission->id]);
        $viewer = User::factory()->withRole($role->slug)->create(['is_active' => true]);
        $requester = User::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);
        $purchaseRequest = PurchaseRequest::factory()->create(['requested_by' => $requester->id]);
        $rfq = RequestForQuote::factory()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'created_by' => $viewer->id,
        ]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.rfq_subject_access',
                'subject_type' => RequestForQuote::class,
                'subject_id' => $rfq->id,
                'user_ids' => [$outsider->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($rfq, [$viewer, $requester, $outsider], 'test.rfq_subject_access', [
            'title' => 'RFQ update',
            'link_to' => '/purchasing/rfqs/'.$rfq->hash_id,
        ], emailEnabled: false);

        foreach ([$viewer, $requester] as $recipient) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id, 'type' => 'test.rfq_subject_access']);
            Event::assertDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $recipient->id);
        }
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $outsider->id, 'type' => 'test.rfq_subject_access']);
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $outsider->id);
        $this->assertTrue(NotificationSubjectAccessRegistry::supports(RequestForQuote::class));
    }

    public function test_supplier_performance_subject_requires_the_detail_route_and_permission(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        $permission = Permission::firstOrCreate(
            ['slug' => 'purchasing.suppliers.performance.view'],
            ['name' => 'View supplier performance', 'module' => 'purchasing'],
        );
        $role = Role::firstOrCreate(['slug' => 'notification_supplier_score_viewer'], ['name' => 'Notification Supplier Score Viewer']);
        $role->permissions()->sync([$permission->id]);
        $viewer = User::factory()->withRole($role->slug)->create(['is_active' => true]);
        $withoutPermission = User::factory()->create(['is_active' => true]);
        $vendor = Vendor::factory()->create();
        $link = '/purchasing/suppliers/'.$vendor->hash_id.'/performance';

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.supplier_performance_subject_access',
                'subject_type' => Vendor::class,
                'subject_id' => $vendor->id,
                'user_ids' => [$withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($vendor, [$viewer, $withoutPermission], 'test.supplier_performance_subject_access', [
            'title' => 'Supplier score changed',
            'link_to' => $link,
        ], emailEnabled: false);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $viewer->id, 'type' => 'test.supplier_performance_subject_access']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withoutPermission->id, 'type' => 'test.supplier_performance_subject_access']);
        $this->assertFalse(NotificationSubjectAccessRegistry::userCanViewRecord($viewer, $vendor, '/purchasing/suppliers'));
        $this->assertTrue(NotificationSubjectAccessRegistry::supports(Vendor::class));
    }
}
