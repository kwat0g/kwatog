<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\Inventory\Enums\ItemCostReviewStatus as Status;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ItemCostReviewService
{
    public function capture(GrnItem $line, string $cost): ItemCostReview
    {
        $review = ItemCostReview::firstOrCreate(['grn_item_id' => $line->id], ['item_id' => $line->item_id, 'previous_cost' => $line->item->standard_cost, 'proposed_cost' => $cost, 'status' => Status::Pending]);
        if ($review->wasRecentlyCreated) {
            app(PricingReviewService::class)->notifyCostReview($review, 'A supplier receipt cost needs Finance review.');
        }

        return $review;
    }

    public function list(): LengthAwarePaginator
    {
        return ItemCostReview::with(['item', 'receiptLine.grn'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(25);
    }

    public function renew(ItemCostReview $review, User $by, string $notes): ItemCostReview
    {
        abort_unless($by->hasPermission('inventory.costs.review'), 403);

        return DB::transaction(function () use ($review, $by, $notes) {
            $item = Item::lockForUpdate()->findOrFail($review->item_id);
            $row = ItemCostReview::lockForUpdate()->findOrFail($review->id);
            $line = $row->receiptLine()->with('grn')->firstOrFail();
            $cost = bcadd((string) $line->unit_cost, (string) ($line->landed_cost_unit ?? '0'), 4);
            if ($row->status !== Status::Rejected || ! $item->is_active || ! $line->grn->status->isBillable() || bccomp($line->quantity_accepted, '0', 3) <= 0 || bccomp($cost, '0', 4) <= 0) {
                throw new BusinessRuleException('Only rejected reviews with an eligible receipt can be renewed.');
            }
            if (bccomp($cost, $row->proposed_cost, 4) === 0 && bccomp($item->standard_cost, $row->previous_cost, 4) === 0) {
                throw new BusinessRuleException('Review basis has not changed. Keep the recorded rejection.');
            }
            $history = [...($row->history ?? []), ['version' => $row->version, 'previous_cost' => $row->previous_cost, 'proposed_cost' => $row->proposed_cost, 'status' => $row->status->value, 'reviewed_by' => $row->reviewed_by ? app('hashids')->encode((int) $row->reviewed_by) : null, 'notes' => $row->notes, 'renewed_by' => $by->hash_id, 'renewal_reason' => $notes, 'at' => now()->toISOString()]];
            $row->forceFill(['history' => $history, 'version' => $row->version + 1, 'previous_cost' => $item->standard_cost, 'proposed_cost' => $cost, 'status' => Status::Pending, 'reviewed_by' => null, 'reviewed_at' => null, 'notes' => null])->save();
            app(PricingReviewService::class)->notifyCostReview($row, 'Corrected supplier cost review requires Finance approval.');

            return $row->load(['item', 'receiptLine.grn']);
        }, 3);
    }

    public function decide(ItemCostReview $review, User $by, string $decision, string $notes, ?int $version = null): ItemCostReview
    {
        abort_unless(ApprovalDelegation::canActForPermission($by, 'inventory.costs.approve'), 403);

        return DB::transaction(function () use ($review, $by, $decision, $notes, $version): ItemCostReview {
            $item = Item::withTrashed()->lockForUpdate()->findOrFail($review->item_id);
            $row = ItemCostReview::lockForUpdate()->findOrFail($review->id);
            if ($version !== null && $row->version !== $version) {
                throw new BusinessRuleException('Cost review changed. Refresh before deciding.');
            }
            $target = $decision === 'approve' ? Status::Approved : Status::Rejected;
            if ($row->status === $target) {
                return $row->load(['item', 'receiptLine.grn']);
            }
            if ($row->status !== Status::Pending) {
                throw new BusinessRuleException('This cost review is already closed.');
            }
            if ($decision === 'approve') {
                $line = $row->receiptLine()->with('grn')->firstOrFail();
                if ($item->trashed() || ! $item->is_active || ! $line->grn->status->isBillable() || bccomp((string) $line->quantity_accepted, '0', 3) <= 0) {
                    throw new BusinessRuleException('Receipt or item is no longer eligible for cost approval.');
                }
                if (bccomp((string) $row->previous_cost, (string) $item->standard_cost, 4) !== 0) {
                    throw new BusinessRuleException('Standard cost changed after this review was opened. Reject this stale review and review the latest receipt.');
                }
                if (bccomp((string) $row->proposed_cost, '0', 4) <= 0) {
                    throw new BusinessRuleException('Approved standard cost must be positive.');
                }
                if (bccomp((string) $row->proposed_cost, bcadd((string) $line->unit_cost, (string) ($line->landed_cost_unit ?? '0'), 4), 4) !== 0) {
                    throw new BusinessRuleException('Receipt cost changed after review opened. Reject this stale review.');
                }
                app(ItemService::class)->update($item, ['standard_cost' => $row->proposed_cost]);
            }
            $row->forceFill(['status' => $target, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'notes' => $notes])->save();

            $audience = app(NotificationService::class)->usersWithPermission('inventory.costs.review');
            app(NotificationService::class)->send($audience, $target === Status::Approved ? 'approval.completed' : 'approval.rejected', [
                'title' => $target === Status::Approved ? 'Supplier cost review approved' : 'Supplier cost review rejected',
                'message' => "The supplier cost review for {$item->code} was {$target->value}.",
                'link_to' => '/crm/price-agreements/cost-reviews',
                'entity_type' => 'item_cost_review',
                'entity_id' => $row->hash_id,
            ], "cost-review:decision:{$row->id}:{$row->version}:{$target->value}");

            return $row->load(['item', 'receiptLine.grn']);
        }, 3);
    }
}
