<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $portal = str_starts_with($request->path(), 'api/v1/b2b/customer/');

        return ['id' => $this->hash_id, 'product' => ['id' => $this->product->hash_id, 'part_number' => $this->product->part_number, 'name' => $this->product->name, 'unit_of_measure' => $this->product->unit_of_measure],
            'customer' => ['id' => $this->customer->hash_id, 'name' => $this->customer->name], 'quantity' => $this->quantity, 'requested_price' => $this->requested_price,
            'notes' => $this->notes, 'status' => $this->status->value, 'version' => $this->version,
            'offer' => $this->when(! $portal || in_array($this->status->value, ['offered', 'accepted', 'rejected'], true), $this->offer ? array_diff_key($this->offer, ['agreement_signature' => true]) : null),
            'cost_review' => $this->when(! $portal && isset($this->cost_review), $this->cost_review),
            'decision_reason' => collect($this->history ?? [])->last(fn ($entry) => in_array($entry['action'], ['withdrawn', 'expired'], true))['data']['notes'] ?? null,
            'history' => $this->when(! $portal, $this->history), 'created_at' => $this->created_at?->toISOString()];
    }
}
