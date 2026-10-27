<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
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
