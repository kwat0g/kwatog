<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier-facing bill item representation. Expense-account metadata is an
 * internal AP concern and is intentionally absent even when eager loaded.
 */
class SupplierBillItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'description' => $this->description,
            'quantity' => (string) $this->quantity,
            'unit' => $this->unit,
            'unit_price' => (string) $this->unit_price,
            'total' => (string) $this->total,
        ];
    }
}
