<?php

declare(strict_types=1);

namespace App\Modules\DSS\Observers;

use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRealtimeService;
use Illuminate\Database\Eloquent\Model;

/** Stages cache retirement before the content-free DSS refresh outbox signal. */
final class DssRealtimeObserver
{
    public function created(Model $model): void
    {
        $this->changed($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->changed($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->changed($model, 'deleted');
    }

    private function changed(Model $model, string $action): void
    {
        if ($model instanceof DssParameter) {
            app(DssParameterService::class)->invalidateCacheAfterCommit($model->detector_id, $model->key);
        }

        app(DssRealtimeService::class)->stage(
            $action,
            $model->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
        );
    }
}
