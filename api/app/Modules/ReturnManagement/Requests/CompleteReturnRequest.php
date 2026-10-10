<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Supplier-return completion may book the remaining stock movement, so the
 * caller may need to name its source warehouse location. The SPA sends a
 * location hash_id; the old inline `exists:` rule compared that string to a
 * bigint column and returned HTTP 500.
 */
class CompleteReturnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->can('return_management.complete') === true
            || $this->user()?->can('return_management.manage') === true;
    }

    protected function hashIdFields(): array
    {
        return ['location_id' => WarehouseLocation::class];
    }

    public function rules(): array
    {
        return [
            // Customer-return stock is released through its linked MRB, which
            // selects the destination. Only supplier-return completion may
            // need this source location. The service re-enforces this per RMA.
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
