<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordSparePartUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete');
    }

    public function rules(): array
    {
        return [
            'item_id'     => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'quantity'    => ['required', 'numeric', 'gt:0'],
        ];
    }
}
