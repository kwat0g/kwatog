<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Services\ItemCostReviewRealtimeService;

final class ItemCostReviewRealtimeObserver
{
    public function created(ItemCostReview $review): void
    {
        $this->stage($review, 'created');
    }

    public function updated(ItemCostReview $review): void
    {
        $this->stage($review, $review->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(ItemCostReview $review): void
    {
        $this->stage($review, 'deleted');
    }

    private function stage(ItemCostReview $review, string $action): void
    {
        app(ItemCostReviewRealtimeService::class)->stage($review, $action);
    }
}
