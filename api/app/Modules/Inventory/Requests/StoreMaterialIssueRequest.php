<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreMaterialIssueRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.issue.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'work_order_id'       => WorkOrder::class,
            'items.*.item_id'     => Item::class,
            'items.*.location_id' => WarehouseLocation::class,
            'items.*.material_reservation_id' => MaterialReservation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'work_order_id'                   => ['nullable', 'integer', 'exists:work_orders,id'],
            'issued_date'                     => ['required', 'date'],
            'reference_text'                  => ['nullable', 'string', 'max:200'],
            'remarks'                         => ['nullable', 'string', 'max:1000'],
            'items'                           => ['required', 'array', 'min:1'],
            'items.*.item_id'                 => ['required', 'integer', 'exists:items,id'],
            'items.*.location_id'             => ['required', 'integer', 'exists:warehouse_locations,id'],
            'items.*.quantity_issued'         => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.issued_uom_code'         => ['nullable', 'string', 'max:20'],
            'items.*.lot_number'              => ['nullable', 'string', 'max:50'],
            'items.*.material_reservation_id' => ['nullable', 'integer', 'exists:material_reservations,id'],
            'items.*.remarks'                 => ['nullable', 'string', 'max:200'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key must contain only letters, numbers, dot, underscore, colon, or hyphen and be at most 128 characters.'],
            ]);
        }

        return $key;
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one line item is required.',
            'items.min'      => 'At least one line item is required.',
        ];
    }
}
