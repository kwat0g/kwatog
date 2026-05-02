<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

class StoreApprovedSupplierRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'   => Item::class,
            'vendor_id' => Vendor::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'        => ['required', 'integer', 'exists:items,id'],
            'vendor_id'      => ['required', 'integer', 'exists:vendors,id'],
            'is_preferred'   => ['nullable', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'last_price'     => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }
}
