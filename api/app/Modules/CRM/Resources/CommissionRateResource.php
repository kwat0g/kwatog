<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->hash_id,
            'employee'        => $this->whenLoaded('employee', fn () => [
                'id'        => $this->employee->hash_id,
                'full_name' => $this->employee->first_name . ' ' . $this->employee->last_name,
            ]),
            'product'         => $this->whenLoaded('product', fn () => $this->product ? [
                'id'   => $this->product->hash_id,
                'code' => $this->product->code,
                'name' => $this->product->name,
            ] : null),
            'rate'            => $this->rate,
            'effective_from'  => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
