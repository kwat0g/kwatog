<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceLog;
use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;

class RecordSparePartUsageRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete')
            || MaintenanceWorkAccess::canRecord($this->route('workOrder'), $this->user());
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id' => Item::class,
            'location_id' => WarehouseLocation::class,
            'work_record_id' => MaintenanceLog::class,
        ];
    }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'work_record_id' => ['required', 'integer', 'exists:maintenance_logs,id'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999999.999'],
        ];
    }
}
