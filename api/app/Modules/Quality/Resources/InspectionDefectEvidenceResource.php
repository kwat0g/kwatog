<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class InspectionDefectEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'category' => $this->category,
            'category_label' => $this->category === 'other' && $this->other_category
                ? $this->other_category
                : Str::headline((string) $this->category),
            'severity' => $this->severity,
            'severity_label' => Str::headline((string) $this->severity),
            'affected_quantity' => (string) $this->affected_quantity,
            'notes' => $this->notes,
            'photo_url' => "/api/v1/quality/defect-evidence/{$this->hash_id}/photo",
            'grn_item_id' => $this->whenLoaded('grnItem', fn () => $this->grnItem?->hash_id),
            'grn_item' => $this->whenLoaded('grnItem', fn () => $this->grnItem ? [
                'id' => $this->grnItem->hash_id,
                'item_code' => $this->grnItem->item?->code,
                'item_name' => $this->grnItem->item?->name,
            ] : null),
            'created_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->hash_id,
                'name' => $this->uploader->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
