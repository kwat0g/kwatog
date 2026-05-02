<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.wo.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'     => Product::class,
            'sales_order_id' => SalesOrder::class,
            'machine_id'     => Machine::class,
            'mold_id'        => Mold::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'      => ['required', 'integer', 'exists:products,id'],
            'sales_order_id'  => ['nullable', 'integer', 'exists:sales_orders,id'],
            'machine_id'      => ['nullable', 'integer', 'exists:machines,id'],
            'mold_id'         => ['nullable', 'integer', 'exists:molds,id'],
            'quantity_target' => ['required', 'integer', 'min:1'],
            'planned_start'   => ['required', 'date'],
            'planned_end'     => ['required', 'date', 'after_or_equal:planned_start'],
            'priority'        => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }
}
