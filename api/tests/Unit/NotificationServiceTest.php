<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    public function test_send_creates_notification_row(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.type', [
            'title'   => 'Test Title',
            'message' => 'Test message body',
            'link_to' => '/test/path',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $user->id,
            'notifiable_type' => User::class,
            'type'            => 'test.type',
        ]);

        $row = DB::table('notifications')->where('notifiable_id', $user->id)->first();
        $data = json_decode($row->data, true);
        $this->assertEquals('Test Title', $data['title']);
        $this->assertEquals('/test/path', $data['link_to']);
    }

    public function test_send_broadcasts_event(): void
    {
        $user = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        $this->service->send($user, 'test.type', [
            'title'   => 'Broadcast Test',
            'message' => 'Should broadcast',
        ]);

        Event::assertDispatched(UserNotificationCreated::class, function ($e) use ($user) {
            return $e->userId === $user->id
                && $e->notification['type'] === 'test.type'
                && $e->notification['data']['title'] === 'Broadcast Test';
        });
    }

    public function test_send_respects_disabled_preference(): void
    {
        $user = User::factory()->create();
        Event::fake();

        DB::table('notification_preferences')->insert([
            'user_id'           => $user->id,
            'notification_type' => 'disabled.type',
            'channel'           => 'in_app',
            'enabled'           => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->service->send($user, 'disabled.type', [
            'title'   => 'Should not appear',
            'message' => 'Blocked by preference',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $user->id,
            'type'          => 'disabled.type',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_send_to_multiple_users(): void
    {
        $users = User::factory()->count(3)->create();
        Event::fake();

        $this->service->send($users->all(), 'multi.type', [
            'title'   => 'Multi',
            'message' => 'Sent to many',
        ]);

        $this->assertEquals(3, DB::table('notifications')->where('type', 'multi.type')->count());
        Event::assertDispatched(UserNotificationCreated::class, 3);
    }
}
