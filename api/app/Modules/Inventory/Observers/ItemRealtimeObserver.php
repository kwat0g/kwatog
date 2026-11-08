<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemRealtimeService;

final class ItemRealtimeObserver
{
    public function created(Item $item): void
    {
        $this->stage($item, 'created');
    }

    public function updated(Item $item): void
    {
        if ($item->wasChanged('deleted_at')) {
            // Soft-delete restoration is reported separately below; Laravel
            // also emits `updated` while clearing deleted_at.
            return;
        }

        $this->stage($item, $item->wasChanged('is_active') ? 'status_changed' : 'updated');
    }

    public function deleted(Item $item): void
    {
        $this->stage($item, 'deleted');
    }

    public function restored(Item $item): void
    {
        $this->stage($item, 'restored');
    }

    private function stage(Item $item, string $action): void
    {
        app(ItemRealtimeService::class)->stage($item, $action);
    }
}
