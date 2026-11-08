<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\ItemCostReviewChanged;
use App\Modules\Inventory\Models\ItemCostReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces writes to one review into a durable after-commit refresh ping. */
final class ItemCostReviewRealtimeService
{
    public function stage(ItemCostReview $review, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ItemCostReviewChanged(
            entityHashId: (string) $review->hash_id,
            action: $action,
            version: $review->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "item-cost-review-refresh:{$review->getKey()}:{$transactionId}");
    }
}
