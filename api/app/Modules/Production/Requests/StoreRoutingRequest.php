<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'notes'                          => ['nullable', 'string', 'max:1000'],
            'operations'                     => ['required', 'array', 'min:1'],
            'operations.*.sequence'          => ['required', 'integer', 'distinct', 'min:1'],
            'operations.*.operation_name'    => ['required', 'string', 'max:100'],
            'operations.*.work_center'       => ['nullable', 'string', 'max:100'],
            'operations.*.machine_id'        => [
                'nullable',
                'integer',
                Rule::exists('machines', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->whereIn('status', [MachineStatus::Idle->value, MachineStatus::Running->value])),
            ],
            'operations.*.mold_id'           => [
                'nullable',
                'integer',
                Rule::exists('molds', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->whereIn('status', [MoldStatus::Available->value, MoldStatus::InUse->value])),
            ],
            'operations.*.setup_time_minutes' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'operations.*.cycle_time_minutes' => ['required', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'operations.*.labor_rate_per_hour' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999.9999'],
            'operations.*.machine_rate_per_hour' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999.9999'],
            'operations.*.overhead_rate_per_hour' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999.9999'],
            'operations.*.description'       => ['nullable', 'string', 'max:500'],
            'operations.*.qc_required'       => ['nullable', 'boolean'],
        ];

        // product_id is required on store, not on update.
        if ($this->isMethod('POST') && ! $this->route('routing')) {
            $rules['product_id'] = [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ];
        }

        return $rules;
    }
}
