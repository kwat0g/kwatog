<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillItemResource extends JsonResource
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
            'expense_account' => $this->whenLoaded('expenseAccount', fn () => $this->expenseAccount ? [
                'id'   => $this->expenseAccount->hash_id,
                'code' => $this->expenseAccount->code,
                'name' => $this->expenseAccount->name,
            ] : null),
        ];
    }
}
