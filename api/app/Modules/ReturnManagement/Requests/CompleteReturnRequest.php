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
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Select the warehouse location the returned stock moves through.',
        ];
    }
}
