<?php

declare(strict_types=1);

namespace App\Modules\Admin\Observers;

use App\Common\Models\ActivityEvent;
use App\Modules\Admin\Services\ActivityFeedRealtimeService;

/** Signals activity-feed appends after their owning transaction commits. */
final class ActivityEventRealtimeObserver
{
    public function created(ActivityEvent $activityEvent): void
    {
        app(ActivityFeedRealtimeService::class)->stage();
    }
}
