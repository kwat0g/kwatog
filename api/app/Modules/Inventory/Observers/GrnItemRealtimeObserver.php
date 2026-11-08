<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Services\GoodsReceiptNoteRealtimeService;

final class GrnItemRealtimeObserver
{
    public function created(GrnItem $item): void
    {
        $this->stageParentRefresh($item, 'created');
    }

    public function updated(GrnItem $item): void
    {
        $this->stageParentRefresh($item, 'updated');
    }

    public function deleted(GrnItem $item): void
    {
        $this->stageParentRefresh($item, 'deleted');
    }

    private function stageParentRefresh(GrnItem $item, string $action): void
    {
        $grn = $item->grn()->first();
        if ($grn === null) {
            return;
        }

        app(GoodsReceiptNoteRealtimeService::class)->stage(
            $grn,
            $action,
            $item->updated_at?->toISOString(),
        );
    }
}
