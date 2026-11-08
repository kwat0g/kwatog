<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Training;
use App\Modules\HR\Services\TrainingCatalogRealtimeService;

/** Captures training-catalog writes from services, controllers, and seed paths. */
final class TrainingCatalogRealtimeObserver
{
    public function created(Training $training): void
    {
        $this->stage($training, 'created');
    }

    public function updated(Training $training): void
    {
        $action = $training->wasChanged('deleted_at') && $training->deleted_at === null
            ? 'restored'
            : 'updated';

        $this->stage($training, $action);
    }

    public function deleted(Training $training): void
    {
        $this->stage($training, 'deleted');
    }

    private function stage(Training $training, string $action): void
    {
        app(TrainingCatalogRealtimeService::class)->stage($training, $action);
    }
}
