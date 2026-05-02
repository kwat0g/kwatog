<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialIssueSlipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'slip_number'    => $this->slip_number,
            'work_order_id'  => $this->work_order_id,
            'issued_date'    => optional($this->issued_date)->toDateString(),
            'status'         => (string) $this->status?->value,
            'total_value'    => (string) $this->total_value,
            'reference_text' => $this->reference_text,
            'remarks'        => $this->remarks,
            'issuer'         => $this->whenLoaded('issuer', fn () => $this->issuer ? [
                'id' => $this->issuer->hash_id, 'name' => $this->issuer->name,
            ] : null),
            'items'          => MaterialIssueSlipItemResource::collection($this->whenLoaded('items')),
            'created_at'     => optional($this->created_at)->toIso8601String(),
        ];
    }
}
