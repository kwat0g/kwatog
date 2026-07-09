<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Quality\Enums\NcrDisposition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REC-08 — release an MRB according to its disposition.
 */
class ReleaseMrbRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.mrb.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'target_location_id' => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'disposition'        => ['required', Rule::in(NcrDisposition::values())],
            // Required only for rework/use_as_is (validated in the service too).
            'target_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }
}
