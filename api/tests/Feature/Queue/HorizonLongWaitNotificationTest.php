<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Common\Listeners\NotifySystemAdminsOnQueueLongWait;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Lock;
use Mockery;
use Tests\TestCase;

final class HorizonLongWaitNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_long_wait_notifies_active_system_admins_and_deduplicates_for_five_minutes(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
        Cache::forget('horizon-long-wait:redis:default');

        try {
            $admin = User::factory()->withRole('system_admin')->create();
            $inactiveAdmin = User::factory()->withRole('system_admin')->create(['is_active' => false]);
            $revokedAdmin = User::factory()->withRole('system_admin')->create();
            $employee = User::factory()->withRole('employee')->create();
            $this->assertTrue($admin->hasPermission('dashboard.admin.view'));
            DB::table('user_permission_overrides')->insert([
                'user_id' => $revokedAdmin->id,
                'permission_id' => DB::table('permissions')->where('slug', 'dashboard.admin.view')->value('id'),
                'type' => 'revoke',
                'granted_by' => $admin->id,
                'reason' => 'The account no longer has access to the queue health destination.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $revokedAdmin->flushPermissionsCache();
            $this->assertFalse($revokedAdmin->hasPermission('dashboard.admin.view'));

            $event = new LongWaitDetected('redis', 'default', 75);
            $horizonLock = Mockery::mock(Lock::class);
            $horizonLock->shouldReceive('get')->times(4)->andReturn(false);
            $this->app->instance(Lock::class, $horizonLock);

            event($event);
            event($event);

            $this->assertSame(1, DB::table('notifications')
                ->where('type', 'queue.long_wait')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $admin->id)
                ->count());
            $this->assertDatabaseMissing('notifications', [
                'type' => 'queue.long_wait',
                'notifiable_type' => User::class,
                'notifiable_id' => $inactiveAdmin->id,
            ]);
            $this->assertDatabaseMissing('notifications', [
                'type' => 'queue.long_wait',
                'notifiable_type' => User::class,
                'notifiable_id' => $revokedAdmin->id,
            ]);
            $this->assertDatabaseMissing('notifications', [
                'type' => 'queue.long_wait',
                'notifiable_type' => User::class,
                'notifiable_id' => $employee->id,
            ]);

            $data = json_decode((string) DB::table('notifications')
                ->where('type', 'queue.long_wait')
                ->where('notifiable_id', $admin->id)
                ->value('data'), true);

            $this->assertSame('Queue backlog: default', $data['title']);
            $this->assertSame('Queue [default] on [redis] has waited 75 seconds.', $data['message']);
            $this->assertSame('/dashboard/admin', $data['link_to']);

            Carbon::setTestNow('2026-10-20 12:04:59 UTC');
            event($event);

            $this->assertSame(1, DB::table('notifications')
                ->where('type', 'queue.long_wait')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $admin->id)
                ->count());

            Carbon::setTestNow('2026-10-20 12:05:01 UTC');
            event($event);

            $this->assertSame(2, DB::table('notifications')
                ->where('type', 'queue.long_wait')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $admin->id)
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_notification_failure_releases_throttle_and_propagates(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
        Cache::forget('horizon-long-wait:redis:default');

        try {
            User::factory()->withRole('system_admin')->create();
            $notifications = Mockery::mock(NotificationService::class);
            $notifications->shouldReceive('sendInApp')
                ->once()
                ->andThrow(new \RuntimeException('notification insert failed'));
            $this->app->instance(NotificationService::class, $notifications);

            try {
                app(NotifySystemAdminsOnQueueLongWait::class)
                    ->handle(new LongWaitDetected('redis', 'default', 75));
                $this->fail('Notification errors must propagate to Horizon.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('notification insert failed', $exception->getMessage());
            }

            $this->assertFalse(Cache::has('horizon-long-wait:redis:default'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
