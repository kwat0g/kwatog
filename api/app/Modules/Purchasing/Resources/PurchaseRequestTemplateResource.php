<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'name' => $this->name,
            'department' => $this->department ? [
                'id' => $this->department->hash_id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null,
            'items' => $this->items,
            'notes' => $this->notes,
            'created_by' => $this->creator?->name,
            'is_active' => $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
