<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Services\ItemRealtimeService;
use Illuminate\Database\Eloquent\Model;

/** Refreshes item consumers when linked category, unit or conversion data changes. */
final class InventoryItemCatalogRealtimeObserver
{
    public function created(Model $record): void
    {
        $this->stage();
    }

    public function updated(Model $record): void
    {
        $this->stage();
    }

    public function deleted(Model $record): void
    {
        $this->stage();
    }

    public function restored(Model $record): void
    {
        $this->stage();
    }

    private function stage(): void
    {
        app(ItemRealtimeService::class)->stageCatalog('updated');
    }
}
