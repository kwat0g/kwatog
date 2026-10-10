<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use Illuminate\Console\Command;

class ReviewPricingAgreements extends Command
{
    protected $signature = 'pricing:review-agreements {--dispatch-only : Dispatch pending pricing refreshes without scanning the catalog}';

    protected $description = 'Refresh stale active BOM estimates, report pricing risks, and remind agreement owners before expiry';

    public function handle(PricingReviewService $review): int
    {
        $ids = OutboxMessage::query()
            ->where('event_type', BomPricingReviewRequested::class)
            ->where(function ($query): void {
                $query->where(fn ($pending) => $pending->where('status', 'pending')->where('available_at', '<=', now()))
                    ->orWhere(fn ($processing) => $processing->where('status', 'processing')->where(fn ($lease) => $lease->whereNull('locked_at')->orWhere('locked_at', '<=', now()->subMinutes(10))));
            })->orderBy('available_at')->limit(100)->pluck('id');
        $failed = false;
        foreach ($ids as $id) {
            try {
                app(OutboxDispatcher::class)->dispatch($id);
            } catch (\Throwable $error) {
                report($error);
                $this->error("Pricing refresh {$id} failed; retained for retry.");
                $failed = true;
            }
        }
        if ($this->option('dispatch-only')) {
            $this->info("Dispatched {$ids->count()} pricing review requests.");

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        // ponytail: bounded chunks scan the product catalog daily; target changed rows if catalog scale warrants it.
        Product::active()->chunkById(50, fn ($products) => $review->handle(new BomPricingReviewRequested($products->pluck('id')->all(), 'scheduled_review')));
        $review->reminders();
        app(\App\Modules\CRM\Services\PriceNegotiationService::class)->expire();
        // Recover recent supplier-cost alerts without replaying accounting listeners.
        GoodsReceiptNote::query()->whereIn('status', GrnStatus::billableValues())
            ->where('accepted_at', '>=', today()->subDay())
            ->chunkById(50, function ($receipts) use ($review): void {
                foreach ($receipts as $receipt) {
                    $review->receiptCostChanged(new GoodsReceiptNoteAccepted($receipt));
                }
            });
        $this->info('Pricing review completed. Existing prices and orders were preserved.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
