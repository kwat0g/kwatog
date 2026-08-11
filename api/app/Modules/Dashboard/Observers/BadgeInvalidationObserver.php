<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Observers;

use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Services\BadgeService;
use App\Common\Services\OutboxService;
use Illuminate\Database\Eloquent\Model;

/**
 * Polish Task S2 (real-time) — generic observer registered against every
 * model that backs a sidebar badge. Any create/update/delete bumps the badge
 * cache version (instant global invalidation) and broadcasts BadgesChanged so
 * connected clients refetch immediately.
 */
class BadgeInvalidationObserver
{
    public function created(Model $model): void
    {
        $this->invalidate($model);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        BadgeService::touch();
        $version = (string) ($model->getRawOriginal('updated_at') ?? microtime(true));
        app(OutboxService::class)->record(
            new BadgesChanged(),
            'badges:'.hash('sha256', implode('|', [$model::class, (string) $model->getKey(), $version])),
        );
    }
}
