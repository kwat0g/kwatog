<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Services\ProfileUpdateRequestRealtimeService;

/** Covers submission and every Eloquent review-state write. */
final class ProfileUpdateRequestRealtimeObserver
{
    public function created(ProfileUpdateRequest $request): void
    {
        $this->stage($request, 'created');
    }

    public function updated(ProfileUpdateRequest $request): void
    {
        $this->stage($request, $request->wasChanged('status') ? 'status_changed' : 'updated');
    }

    private function stage(ProfileUpdateRequest $request, string $action): void
    {
        app(ProfileUpdateRequestRealtimeService::class)->stage($request, $action);
    }
}
