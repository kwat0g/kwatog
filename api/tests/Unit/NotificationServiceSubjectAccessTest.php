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
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\SupplierItemListing;
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
        $permission = Permission::firstOrCreate(
            ['slug' => 'purchasing.view'],
            ['name' => 'View purchasing', 'module' => 'purchasing'],
        );
        $role = Role::firstOrCreate(
            ['slug' => 'notification_subject_viewer'],
            ['name' => 'Notification Subject Viewer'],
        );
        $role->permissions()->attach($permission->id);
        $owner = User::factory()->withRole($role->slug)->create();
        $unrelated = User::factory()->withRole($role->slug)->create();
        $withoutPermission = User::factory()->create();
        $request = PurchaseRequest::factory()->create(['requested_by' => $owner->id]);

        Event::fake([UserNotificationCreated::class]);
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'test.subject_access',
                'subject_type' => PurchaseRequest::class,
                'subject_id' => $request->id,
                'user_ids' => [$unrelated->id, $withoutPermission->id],
            ],
        );

        app(NotificationService::class)->sendForSubject($request, [$owner, $unrelated, $withoutPermission], 'test.subject_access', [
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
        Event::assertNotDispatched(UserNotificationCreated::class, fn ($event): bool => $event->userId === $withoutPermission->id);
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
}
