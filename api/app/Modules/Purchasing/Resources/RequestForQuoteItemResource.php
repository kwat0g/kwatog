<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestForQuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $awarded = null;
        if ($this->relationLoaded('awards')) {
            $awarded = '0';
            foreach ($this->awards as $award) {
                $awarded = bcadd($awarded, (string) $award->awarded_quantity, 4);
            }
        }

        return [
            'id' => $this->hash_id,
            'description' => $this->description,
            'specification' => $this->specification,
            'quantity' => (string) $this->quantity,
            'unit' => $this->unit,
            'required_delivery_date' => optional($this->required_delivery_date)->toDateString(),
            // Only on the internal view: what the award covered and what went back to the PR.
            'awarded_quantity' => $this->when($awarded !== null, $awarded),
            'remaining_quantity' => $this->when($awarded !== null, fn () => bccomp((string) $this->quantity, (string) $awarded, 4) > 0
                ? bcsub((string) $this->quantity, (string) $awarded, 4)
                : '0.0000'),
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ] : null),
        ];
    }
}
