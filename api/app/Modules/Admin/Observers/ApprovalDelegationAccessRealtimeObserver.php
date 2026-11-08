<?php

declare(strict_types=1);

namespace App\Modules\Admin\Observers;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\UserAccessRefreshService;
use App\Modules\Admin\Services\ApprovalDelegationRealtimeService;

/** Refresh a delegate's SPA authorization when an effective delegation changes. */
final class ApprovalDelegationAccessRealtimeObserver
{
    private const ACCESS_FIELDS = [
        'delegator_user_id', 'delegate_user_id', 'role_slug',
        'starts_at', 'ends_at', 'is_active',
    ];

    public function created(ApprovalDelegation $delegation): void
    {
        $this->notify([$delegation->delegate_user_id]);
        $this->stageListRefresh($delegation, 'created');
    }

    public function updated(ApprovalDelegation $delegation): void
    {
        if ($delegation->wasChanged(self::ACCESS_FIELDS)) {
            $userIds = [$delegation->delegate_user_id];
            if ($delegation->wasChanged('delegate_user_id')) {
                $userIds[] = $delegation->getRawOriginal('delegate_user_id');
            }
            $this->notify($userIds);
        }

        if ($delegation->wasChanged([...self::ACCESS_FIELDS, 'reason'])) {
            $this->stageListRefresh($delegation, 'updated');
        }
    }

    public function deleted(ApprovalDelegation $delegation): void
    {
        $this->notify([$delegation->delegate_user_id]);
        $this->stageListRefresh($delegation, 'deleted');
    }

    /** @param array<int, int|string|null> $userIds */
    private function notify(array $userIds): void
    {
        app(UserAccessRefreshService::class)->stageForUsers($userIds);
    }

    private function stageListRefresh(ApprovalDelegation $delegation, string $action): void
    {
        app(ApprovalDelegationRealtimeService::class)->stage($delegation, $action);
    }
}
