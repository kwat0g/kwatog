<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;

class ConvertPrToPoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $resolved = [];
        foreach ((array) $this->input('vendor_map', []) as $lineId => $vendorId) {
            $line = is_numeric($lineId)
                ? (int) $lineId
                : HashIdFilter::decode((string) $lineId, PurchaseRequestItem::class);
            $vendor = is_numeric($vendorId)
                ? (int) $vendorId
                : HashIdFilter::decode((string) $vendorId, Vendor::class);
            if ($line && $vendor) {
                $resolved[$line] = $vendor;
            }
        }
        $this->merge(['vendor_map' => $resolved]);
    }
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    public function rules(): array
    {
        return [
            // map: { pr_item_id => vendor_id }
            'vendor_map'   => ['required', 'array', 'min:1'],
            'vendor_map.*' => ['required', 'integer'],
        ];
    }
}
