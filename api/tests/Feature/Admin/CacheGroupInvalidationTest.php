<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Events\CacheGroupInvalidated;
use App\Modules\Admin\Services\CacheGroupInvalidationService;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CacheGroupInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_group_rotates_dashboard_and_badge_generations(): void
    {
        $actor = User::factory()->create();
        $dashboardGeneration = DashboardCacheGeneration::current();
        $badgeGeneration = BadgeService::version();
        Event::fake([CacheGroupInvalidated::class]);

        $result = app(CacheGroupInvalidationService::class)->clear('dashboards', $actor);

        $this->assertSame('generation_rotated', $result['invalidation']);
        $this->assertNotSame($dashboardGeneration, DashboardCacheGeneration::current());
        $this->assertNotSame($badgeGeneration, BadgeService::version());
        Event::assertDispatched(CacheGroupInvalidated::class);

        $outbox = DB::table('event_outbox')
            ->where('event_type', CacheGroupInvalidated::class)
            ->first();

        $this->assertNotNull($outbox, 'A committed cache-group rotation must leave a durable refresh signal.');
        $this->assertSame('dashboards', json_decode((string) $outbox->payload, true, flags: JSON_THROW_ON_ERROR)['group']);
        $this->assertSame('published', $outbox->status);
    }
}
