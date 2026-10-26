<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
}
