<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => (int) $this->id,
            'description' => $this->description,
            'quantity'    => (string) $this->quantity,
            'unit'        => $this->unit,
            'unit_price'  => (string) $this->unit_price,
            'total'       => (string) $this->total,
            'revenue_account' => $this->whenLoaded('revenueAccount', fn () => $this->revenueAccount ? [
                'id'   => $this->revenueAccount->hash_id,
                'code' => $this->revenueAccount->code,
                'name' => $this->revenueAccount->name,
            ] : null),
        ];
    }
}
