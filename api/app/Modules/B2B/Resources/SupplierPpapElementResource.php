<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier-facing PPAP element contract.
 *
 * The internal Quality\Resources\PpapElementResource emits `document_path` —
 * the raw private storage path of the uploaded evidence. There is no supplier
 * PPAP download route, so that field is of no use to the portal client while
 * disclosing the document vault's layout to an external principal. This
 * resource drops it and keeps the element identity and status the supplier
 * needs to see the review state of its own submission.
 */
class SupplierPpapElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'element_type' => $this->element_type instanceof BackedEnum
                ? $this->element_type->value
                : $this->element_type,
            'element_label' => $this->element_type instanceof PpapElementType
                ? $this->element_type->label()
                : null,
            'status' => $this->status instanceof BackedEnum
                ? $this->status->value
                : $this->status,
            'status_label' => $this->status instanceof PpapElementStatus
                ? $this->status->label()
                : null,
            'has_document' => filled($this->document_path),
            'notes' => $this->notes,
        ];
    }
}
