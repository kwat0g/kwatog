<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService;
    }

    public function test_send_creates_notification_row(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.type', [
            'title' => 'Test Title',
            'message' => 'Test message body',
            'link_to' => '/test/path',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
            'type' => 'test.type',
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
            'title' => 'Broadcast Test',
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
            'user_id' => $user->id,
            'notification_type' => 'disabled.type',
            'channel' => 'in_app',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->send($user, 'disabled.type', [
            'title' => 'Should not appear',
            'message' => 'Blocked by preference',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'disabled.type',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_send_to_multiple_users(): void
    {
        $users = User::factory()->count(3)->create();
        Event::fake();

        $this->service->send($users->all(), 'multi.type', [
            'title' => 'Multi',
            'message' => 'Sent to many',
        ]);

        $this->assertEquals(3, DB::table('notifications')->where('type', 'multi.type')->count());
        Event::assertDispatched(UserNotificationCreated::class, 3);
    }

    public function test_dedupe_key_makes_replayed_send_idempotent(): void
    {
        $user = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        $payload = ['title' => 'Replay safe', 'message' => 'Only once'];
        $this->service->send($user, 'replay.safe', $payload, 'event:123');
        $this->service->send($user, 'replay.safe', $payload, 'event:123');

        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('dedupe_key', 'event:123')
            ->count());
        Event::assertDispatched(UserNotificationCreated::class, 1);
    }

    public function test_dss_recommendation_delivery_is_idempotent_and_capped_per_local_day(): void
    {
        $user = User::factory()->create();
        config(['dss.notification_daily_cap' => 2]);
        Event::fake([UserNotificationCreated::class]);
        Mail::fake();
        $payload = ['title' => 'Review this issue', 'message' => 'A critical issue needs review.'];

        $this->assertTrue($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-1:opened'));
        $this->assertTrue($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-1:opened'));
        $this->assertTrue($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-2:critical'));
        $this->assertFalse($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-3:opened'));
        config(['dss.notification_daily_cap' => 0]);
        $this->assertTrue($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-1:opened'));
        $this->assertFalse($this->service->sendDssRecommendationInApp($user, $payload, 'occurrence-4:opened'));

        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $user->id)->where('type', 'dss.recommendation')->count());
        Event::assertDispatched(UserNotificationCreated::class, 2);
        Mail::assertNothingOutgoing();
    }

    public function test_dss_recommendation_delivery_resets_at_application_local_midnight(): void
    {
        $user = User::factory()->create();
        $previousAppTimezone = config('app.timezone');
        config(['app.timezone' => 'Asia/Manila', 'dss.notification_daily_cap' => 1]);
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Manila');
        Carbon::setTestNow('2026-10-24 23:59:00');

        try {
            $this->assertTrue($this->service->sendDssRecommendationInApp($user, ['title' => 'First', 'message' => 'First notice.'], 'first'));
            Carbon::setTestNow('2026-10-25 00:01:00');
            $this->assertTrue($this->service->sendDssRecommendationInApp($user, ['title' => 'Second', 'message' => 'New local day.'], 'second'));
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($previousTimezone);
            config(['app.timezone' => $previousAppTimezone]);
        }

        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $user->id)->where('type', 'dss.recommendation')->count());
    }

    public function test_dss_recommendation_delivery_respects_opt_out_and_disabled_cap(): void
    {
        $user = User::factory()->create();
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'notification_type' => 'dss.recommendation',
            'channel' => 'in_app',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['dss.notification_daily_cap' => 3]);

        $this->assertFalse($this->service->sendDssRecommendationInApp($user, ['title' => 'No notice', 'message' => 'Opted out.'], 'opted-out'));
        config(['dss.notification_daily_cap' => 0]);
        $this->assertFalse($this->service->sendDssRecommendationInApp($user, ['title' => 'No notice', 'message' => 'Publishing disabled.'], 'disabled-cap'));
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->where('type', 'dss.recommendation')->count());
    }
}
