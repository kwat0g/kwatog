<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Modules\B2B\Models\SupplierPortalUser $user */
        $user = $this->user('supplier_portal');
        if (! $user) {
            return []; // route middleware handles unauthenticated
        }

        return [
            'purchase_order_id' => ['required', function (string $attr, mixed $val, \Closure $fail) use ($user) {
                $decoded = HashIdFilter::decode($val, PurchaseOrder::class);
                $po = $decoded ? PurchaseOrder::find($decoded) : null;
                if (! $po || $po->vendor_id !== $user->vendor_id) {
                    $fail('Invalid purchase order.');
                }
            }],
            'month'                => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.product_name' => ['required', 'string', 'max:255'],
            'lines.*.quantity'     => ['required', 'numeric', 'min:0.01'],
            'lines.*.notes'        => ['nullable', 'string', 'max:500'],
        ];
    }
}
