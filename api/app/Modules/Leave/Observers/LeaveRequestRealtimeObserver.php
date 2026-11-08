<?php

declare(strict_types=1);

namespace App\Modules\Leave\Observers;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveRequestRealtimeService;

/** Keep leave row refreshes and badge/cache invalidation on one write path. */
final class LeaveRequestRealtimeObserver
{
    public function created(LeaveRequest $request): void
    {
        $this->changed($request, 'created');
    }

    public function updated(LeaveRequest $request): void
    {
        $this->changed($request, 'updated');
    }

    public function deleted(LeaveRequest $request): void
    {
        $this->changed($request, 'deleted');
    }

    public function restored(LeaveRequest $request): void
    {
        $this->changed($request, 'restored');
    }

    private function changed(LeaveRequest $request, string $action): void
    {
        $realtime = app(LeaveRequestRealtimeService::class);
        $transactionId = $realtime->transactionToken();

        // Register cache generation rotation before either outbox dispatch
        // callback, so viewers cannot refetch a pre-commit dashboard snapshot.
        BadgeService::touch();
        app(OutboxService::class)->recordCoalesced(
            new BadgesChanged,
            'leave-badges:'.$transactionId,
        );
        $realtime->stage($request, $action, $transactionId);
    }
}
