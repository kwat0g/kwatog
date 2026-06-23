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
            'ppap_level'        => ['required', Rule::in(PpapLevel::values())],
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

        if (! empty($d['product_id'])) {
            $d['product_id'] = Product::tryDecodeHash($d['product_id']) ?: null;
        }
        if (! empty($d['purchase_order_id'])) {
            $d['purchase_order_id'] = PurchaseOrder::tryDecodeHash($d['purchase_order_id']) ?: null;
        }
        return $d;
    }
}
