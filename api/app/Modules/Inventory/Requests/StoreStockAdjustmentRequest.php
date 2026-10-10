<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentDirection;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    use ResolvesHashIds { prepareForValidation as resolveHashIds; }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.adjust') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->resolveHashIds();
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'     => Item::class,
            'location_id' => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'     => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'direction'   => ['required', Rule::enum(StockAdjustmentDirection::class)],
            'quantity'    => ['required', 'decimal:0,3', 'min:0.001'],
            'unit_cost'   => ['required_if:direction,in', 'nullable', 'decimal:0,4', 'min:0'],
            'reason'      => ['required', 'string', 'min:10', 'max:500'],
            // OGAMI-012 — optional structured reason code (enum-backed).
            'reason_code' => ['nullable', Rule::in(StockAdjustmentReason::values())],
            'idempotency_key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Please provide a reason of at least 10 characters (audit trail).',
        ];
    }
}
