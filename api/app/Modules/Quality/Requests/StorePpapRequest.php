<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Enums\PpapLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePpapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.ppap.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'vendor_id'         => ['required', 'string'],
            'item_id'           => ['required', 'string'],
            'product_id'        => ['nullable', 'string'],
            'purchase_order_id' => ['nullable', 'string'],
            // `Rule::in` compares LOOSELY, so against the numeric-valued PpapLevel the
            // strings '1e0' and '1.0' and the float 1.0 all satisfy in:1,2,3,4,5 —
            // '1e0' then overflowed `ppap_level varchar(1)` as a 500, and 1.0 was
            // silently coerced to Level 1. `Rule::enum` compares strictly.
            'ppap_level'        => ['required', 'string', Rule::enum(PpapLevel::class)],
            'submission_date'   => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Decode hash IDs to integer FKs. */
    public function validatedData(): array
    {
        $d = $this->validated();
        $d['vendor_id'] = Vendor::tryDecodeHash($d['vendor_id']);
        $d['item_id']   = Item::tryDecodeHash($d['item_id']);
        abort_if(! $d['vendor_id'], 422, 'Invalid vendor.');
        abort_if(! $d['item_id'], 422, 'Invalid item.');

        // An unresolvable optional reference used to be silently converted to null, so a
        // caller believed it had linked a product or PO that was never stored. Refuse it.
        if (! empty($d['product_id'])) {
            $d['product_id'] = Product::tryDecodeHash($d['product_id']);
            abort_if(! $d['product_id'], 422, 'Invalid product.');
        }
        if (! empty($d['purchase_order_id'])) {
            $d['purchase_order_id'] = PurchaseOrder::tryDecodeHash($d['purchase_order_id']);
            abort_if(! $d['purchase_order_id'], 422, 'Invalid purchase order.');
        }

        return $d;
    }
}
