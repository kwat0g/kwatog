<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceSubjectAccessTest extends TestCase
{
    use RefreshDatabase;

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
}
