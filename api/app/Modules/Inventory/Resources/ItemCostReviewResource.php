<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ItemCostReviewStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCostReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReview = $user instanceof User && $user->hasPermission('inventory.costs.review');
        $canApprove = $user instanceof User && $this->canApproveCostReviews($request, $user);
        $status = $this->status instanceof ItemCostReviewStatus ? $this->status : ItemCostReviewStatus::from((string) $this->status);

        return ['id' => $this->hash_id, 'item' => ['id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name, 'unit_of_measure' => $this->item->unit_of_measure],
            'receipt_number' => $this->receiptLine->grn->grn_number, 'previous_cost' => $this->previous_cost, 'proposed_cost' => $this->proposed_cost,
            'current_cost' => $this->item->standard_cost, 'version' => $this->version, 'history' => $this->history,
            'status' => $status->value,
            'permissions' => [
                'view' => $canReview,
                'review' => $canReview,
                'approve' => $canApprove,
            ],
            'available_actions' => [
                'approve' => $status === ItemCostReviewStatus::Pending && $canApprove,
                'reject' => $status === ItemCostReviewStatus::Pending && $canApprove,
                'renew' => $status === ItemCostReviewStatus::Rejected && $canReview,
            ],
            'notes' => $this->notes, 'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString()];
    }

    private function canApproveCostReviews(Request $request, User $user): bool
    {
        $key = 'inventory_cost_reviews.approve.'.(string) $user->getAuthIdentifier();
        if (! $request->attributes->has($key)) {
            $request->attributes->set(
                $key,
                ApprovalDelegation::canActForPermission($user, 'inventory.costs.approve'),
            );
        }

        return (bool) $request->attributes->get($key);
    }
}
