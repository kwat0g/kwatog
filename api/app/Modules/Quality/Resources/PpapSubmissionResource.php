<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PpapSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canManage = $viewer instanceof User && $viewer->hasPermission('quality.ppap.manage');
        $status = $this->status instanceof PpapStatus
            ? $this->status
            : PpapStatus::tryFrom((string) $this->status);
        $elements = $this->relationLoaded('elements') ? $this->getRelation('elements') : collect();
        $hasElements = $this->relationLoaded('elements')
            ? $elements->isNotEmpty()
            : $this->elements()->exists();
        $canProgress = $canManage
            && in_array($status, [PpapStatus::Submitted, PpapStatus::UnderReview], true)
            && (int) $this->submitted_by !== (int) $viewer->id;
        $hasUnresolvedElements = $this->relationLoaded('elements')
            ? $elements->contains(static function ($element): bool {
                $elementStatus = $element->status instanceof PpapElementStatus
                    ? $element->status
                    : PpapElementStatus::tryFrom((string) $element->status);

                return ! in_array($elementStatus, [PpapElementStatus::Accepted, PpapElementStatus::NotApplicable], true);
            })
            : $this->elements()
                ->whereNotIn('status', [PpapElementStatus::Accepted->value, PpapElementStatus::NotApplicable->value])
                ->exists();

        return [
            'id'              => $this->hash_id,
            'ppap_number'     => $this->ppap_number,
            'ppap_level'      => $this->ppap_level instanceof \BackedEnum ? $this->ppap_level->value : $this->ppap_level,
            'ppap_level_label'=> $this->ppap_level instanceof \App\Modules\Quality\Enums\PpapLevel ? $this->ppap_level->label() : null,
            'status'          => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label'    => $this->status instanceof \App\Modules\Quality\Enums\PpapStatus ? $this->status->label() : null,
            'submission_date' => optional($this->submission_date)?->toDateString(),
            'rejection_reason'=> $this->rejection_reason,
            'reviewed_at'     => optional($this->reviewed_at)?->toISOString(),
            'approved_at'     => optional($this->approved_at)?->toISOString(),
            'expires_at'      => optional($this->expires_at)?->toISOString(),
            'revision'        => (int) $this->revision,
            'notes'           => $this->notes,
            'permissions'     => [
                'view' => $viewer instanceof User && $viewer->hasPermission('quality.ppap.view'),
                'manage' => $canManage,
            ],
            'available_actions' => [
                'update' => $canManage && $status !== null && ! $status->isTerminal() && $status !== PpapStatus::Approved,
                'submit' => $canManage && $status === PpapStatus::Draft && $hasElements,
                'review' => $canProgress,
                'approve' => $canProgress && ! $hasUnresolvedElements,
                'reject' => $canManage && $status !== null && ! $status->isTerminal() && $status !== PpapStatus::Approved,
                'update_elements' => $canManage && $status !== PpapStatus::Approved,
            ],
            'vendor'          => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id, 'name' => $this->vendor->name,
            ] : null),
            'item'            => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name,
            ] : null),
            'product'         => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->hash_id, 'name' => $this->product->name,
            ] : null),
            'purchase_order'  => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->hash_id, 'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'submitter'       => $this->whenLoaded('submitter', fn () => $this->submitter ? [
                'id' => $this->submitter->hash_id, 'name' => $this->submitter->name,
            ] : null),
            'approver'        => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->hash_id, 'name' => $this->approver->name,
            ] : null),
            'elements'        => $this->whenLoaded('elements', fn () => PpapElementResource::collection($this->elements)->resolve()),
            'created_at'      => optional($this->created_at)?->toISOString(),
        ];
    }
}
