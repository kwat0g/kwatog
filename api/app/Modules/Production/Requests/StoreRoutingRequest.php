<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoutingRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.routings.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'              => Product::class,
            'operations.*.machine_id' => Machine::class,
            'operations.*.mold_id'    => Mold::class,
        ];
    }

    public function rules(): array
    {
        $rules = [
            'notes'                          => ['nullable', 'string'],
            'operations'                     => ['required', 'array', 'min:1'],
            'operations.*.sequence'          => ['required', 'integer', 'min:1'],
            'operations.*.operation_name'    => ['required', 'string', 'max:100'],
            'operations.*.work_center'       => ['nullable', 'string', 'max:100'],
            'operations.*.machine_id'        => ['nullable', 'integer', 'exists:machines,id'],
            'operations.*.mold_id'           => ['nullable', 'integer', 'exists:molds,id'],
            'operations.*.setup_time_minutes' => ['nullable', 'numeric', 'min:0'],
            'operations.*.cycle_time_minutes' => ['required', 'numeric', 'min:0.01'],
            'operations.*.description'       => ['nullable', 'string'],
            'operations.*.qc_required'       => ['nullable', 'boolean'],
        ];

        // product_id is required on store, not on update.
        if ($this->isMethod('POST') && ! $this->route('routing')) {
            $rules['product_id'] = ['required', 'integer', 'exists:products,id'];
        }

        return $rules;
    }
}
