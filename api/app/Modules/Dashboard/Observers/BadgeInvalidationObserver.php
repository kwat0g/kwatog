<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Observers;

use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Services\BadgeService;
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
        $this->invalidate();
    }

    public function updated(Model $model): void
    {
        $this->invalidate();
    }

    public function deleted(Model $model): void
    {
        $this->invalidate();
    }

    private function invalidate(): void
    {
        BadgeService::touch();
        BadgesChanged::dispatch();
    }
}
