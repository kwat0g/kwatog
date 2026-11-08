<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;

final class StockCountAvailableActions
{
    /** @return list<string> */
    public static function forSession(StockCountSession $session, ?User $actor): array
    {
        if (! $actor?->hasPermission('inventory.stock_count.manage')) {
            return [];
        }

        if ($session->status === StockCountSessionStatus::Draft) {
            return ['start', 'cancel'];
        }

        if ($session->status !== StockCountSessionStatus::InProgress) {
            return [];
        }

        $actions = ['cancel'];
        if ($session->relationLoaded('items')
            && (int) $session->created_by !== (int) $actor->id
            && ! $session->items->contains(static fn (StockCountItem $item): bool => (int) $item->counted_by === (int) $actor->id
                || (int) $item->verified_by === (int) $actor->id
            )) {
            $actions[] = 'complete';
        }

        return $actions;
    }

    /** @return list<string> */
    public static function forItem(StockCountItem $item, ?User $actor): array
    {
        if (! $actor || ! $item->relationLoaded('session')) {
            return [];
        }

        /** @var StockCountSession $session */
        $session = $item->getRelation('session');
        if ($session->status !== StockCountSessionStatus::InProgress) {
            return [];
        }

        $actions = [];
        if ($actor->hasPermission('inventory.stock_count.manage')
            && in_array($item->status, [
                StockCountItemStatus::Pending,
                StockCountItemStatus::Counted,
                StockCountItemStatus::Verified,
            ], true)) {
            $actions[] = 'record_count';
        }

        $exceptUserIds = array_values(array_filter([
            (int) $item->counted_by,
            (int) $session->created_by,
        ]));
        if ($item->status === StockCountItemStatus::Counted
            && ApprovalDelegation::canActForPermission($actor, 'inventory.stock_count.manage', $exceptUserIds)
            && (int) $item->counted_by !== (int) $actor->id
            && (int) $session->created_by !== (int) $actor->id
            && bccomp(
                ltrim((string) $item->variance_percent, '-'),
                (string) app(SettingsService::class)->requiredFloat('inventory.stock_count.variance_tolerance_pct', 0),
                2,
            ) > 0) {
            $actions[] = 'approve_variance';
        }

        return $actions;
    }
}
