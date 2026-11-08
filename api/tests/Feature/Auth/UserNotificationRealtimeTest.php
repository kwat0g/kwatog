<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Events\UserNotificationChanged;
use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\UserNotificationService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserNotificationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_marking_a_notification_read_signals_the_same_users_other_tabs_after_commit(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $this->insertNotification($user, $id);
        Event::fake([UserNotificationChanged::class]);

        $readAt = app(UserNotificationService::class)->markRead($user, $id);

        Event::assertDispatched(UserNotificationChanged::class, function (UserNotificationChanged $event) use ($user, $id, $readAt): bool {
            return $event->userId === (int) $user->id
                && $event->action === 'read'
                && $event->notificationId === $id
                && $event->occurredAt === $readAt;
        });
    }

    public function test_read_all_delete_and_clear_read_actions_signal_only_when_rows_change(): void
    {
        $user = User::factory()->create();
        $unreadId = (string) Str::uuid();
        $readId = (string) Str::uuid();
        $this->insertNotification($user, $unreadId);
        $this->insertNotification($user, $readId, now()->subMinute()->toISOString());
        Event::fake([UserNotificationChanged::class]);

        self::assertSame(1, app(UserNotificationService::class)->markAllRead($user));
        app(UserNotificationService::class)->delete($user, $unreadId);
        self::assertSame(1, app(UserNotificationService::class)->deleteAllRead($user));
        self::assertSame(0, app(UserNotificationService::class)->deleteAllRead($user));

        Event::assertDispatched(UserNotificationChanged::class, 3);
        Event::assertDispatched(UserNotificationChanged::class, fn (UserNotificationChanged $event): bool => $event->action === 'read_all' && $event->userId === (int) $user->id);
        Event::assertDispatched(UserNotificationChanged::class, fn (UserNotificationChanged $event): bool => $event->action === 'deleted' && $event->notificationId === $unreadId);
        Event::assertDispatched(UserNotificationChanged::class, fn (UserNotificationChanged $event): bool => $event->action === 'cleared' && $event->notificationId === null);
    }

    public function test_change_signal_contains_only_refresh_metadata(): void
    {
        $event = new UserNotificationChanged(123, 'read', 'notification-id', '2026-10-30T12:00:00.000000Z', 'event-id');

        self::assertSame('notification.changed', $event->broadcastAs());
        self::assertSame([
            'event_id' => 'event-id',
            'action' => 'read',
            'id' => 'notification-id',
            'occurred_at' => '2026-10-30T12:00:00.000000Z',
        ], $event->broadcastWith());
    }

    public function test_internal_notification_create_and_read_signals_have_durable_outbox_rows(): void
    {
        $user = User::factory()->create();
        Event::fake([UserNotificationCreated::class, UserNotificationChanged::class]);

        app(NotificationService::class)->sendInApp($user, 'test.notice', ['title' => 'Private notice']);

        $notificationId = (string) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->value('id');
        self::assertNotSame('', $notificationId);

        app(UserNotificationService::class)->markRead($user, $notificationId);

        $this->assertDatabaseHas('event_outbox', ['event_type' => UserNotificationCreated::class]);
        $this->assertDatabaseHas('event_outbox', ['event_type' => UserNotificationChanged::class]);
        Event::assertDispatched(UserNotificationCreated::class);
        Event::assertDispatched(UserNotificationChanged::class);
    }

    private function insertNotification(User $user, string $id, ?string $readAt = null): void
    {
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'test.notice',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Private notice'], JSON_THROW_ON_ERROR),
            'read_at' => $readAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
