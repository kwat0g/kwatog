<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.create');
    }

    /**
     * The SPA sends a hashed Machine/Mold id, but `maintainable_id` is a
     * polymorphic key: which model to decode against is only known from
     * `maintainable_type`, so the shared ResolvesHashIds map cannot express it.
     * Decode here, mapping an undecodable hash to 0 so `min:1` 422s rather than
     * the string reaching MaintenanceWorkOrderService's raw (int) cast. An
     * unknown/missing type is left alone so Rule::in reports the real error.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('maintainable_id');
        if ($raw === null || $raw === '') {
            return;
        }

        $model = match ($this->input('maintainable_type')) {
            MaintainableType::Machine->value => Machine::class,
            MaintainableType::Mold->value    => Mold::class,
            default                          => null,
        };
        if ($model === null) {
            return;
        }

        $this->merge(['maintainable_id' => HashIdFilter::decode($raw, $model) ?? 0]);
    }

    public function rules(): array
    {
        return [
            'maintainable_type' => ['required', Rule::in(MaintainableType::values())],
            'maintainable_id'   => ['required', 'integer', 'min:1'],
            'type'              => ['required', Rule::in(MaintenanceWorkOrderType::values())],
            'priority'          => ['required', Rule::in(MaintenancePriority::values())],
            'description'       => ['required', 'string', 'max:5000'],
        ];
    }
}
