<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completion books the stock movement, so the caller must name the warehouse
 * location. The SPA sends a location hash_id; the old inline `exists:` rule
 * compared that string to a bigint column and returned HTTP 500.
 */
class CompleteReturnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->can('return_management.manage') === true;
    }

    protected function hashIdFields(): array
    {
        return ['location_id' => WarehouseLocation::class];
    }

    public function rules(): array
    {
        return [
            // 2026-08-08 — customer-return restock/rework lines move at dispose()
            // now, so completion only needs a location when a line still has to
            // move (supplier return_to_supplier, or a legacy flow that disposed
            // without one). The service re-enforces this per RMA.
            'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Select the warehouse location the returned stock moves through.',
        ];
    }
}
