<?php

declare(strict_types=1);

namespace App\Common\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Events\LongWaitDetected;
use Throwable;

final class NotifySystemAdminsOnQueueLongWait
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(LongWaitDetected $event): void
    {
        $cacheKey = sprintf('horizon-long-wait:%s:%s', $event->connection, $event->queue);
        $dedupeKey = $cacheKey.':'.now()->timestamp;

        if (! Cache::add($cacheKey, $dedupeKey, 300)) {
            return;
        }

        try {
            $admins = User::query()
                ->where('is_active', true)
                ->whereHas('role', static fn ($query) => $query->where('slug', 'system_admin'))
                ->get();

            $this->notifications->sendInApp(
                $admins,
                'queue.long_wait',
                [
                    'title' => 'Queue backlog: '.$event->queue,
                    'message' => sprintf(
                        'Queue [%s] on [%s] has waited %d seconds.',
                        $event->queue,
                        $event->connection,
                        $event->seconds,
                    ),
                ],
                $dedupeKey,
            );
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            throw $exception;
        }
    }
}
