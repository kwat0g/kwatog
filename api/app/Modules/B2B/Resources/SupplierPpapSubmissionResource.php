<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier-facing PPAP submission contract.
 *
 * The supplier endpoint used to return Quality\Resources\PpapSubmissionResource
 * directly. That is the INTERNAL contract: it carries the raw private
 * `document_path` of every element, plus the internal reviewer and approver
 * identities. A supplier may legitimately see the review state of its own
 * submission — including why it was rejected — but not who inside the plant
 * handled it, and never a storage path.
 *
 * Quality's own resources are a dependency of this module and are deliberately
 * left unchanged; this is the B2B-owned allowlist over the same model.
 */
class SupplierPpapSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'ppap_number' => $this->ppap_number,
            'ppap_level' => $this->ppap_level instanceof BackedEnum
                ? $this->ppap_level->value
                : $this->ppap_level,
            'ppap_level_label' => $this->ppap_level instanceof PpapLevel
                ? $this->ppap_level->label()
                : null,
            'status' => $this->status instanceof BackedEnum
                ? $this->status->value
                : $this->status,
            'status_label' => $this->status instanceof PpapStatus
                ? $this->status->label()
                : null,
            'submission_date' => optional($this->submission_date)->toDateString(),
            // The supplier is the party that has to act on a rejection, so the
            // reason and the review/approval timestamps stay. The internal
            // `submitter` and `approver` identities do not.
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => optional($this->reviewed_at)->toIso8601String(),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'revision' => (int) $this->revision,
            'notes' => $this->notes,
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ] : null),
            'elements' => $this->whenLoaded(
                'elements',
                fn () => SupplierPpapElementResource::collection($this->elements)->resolve(),
            ),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
