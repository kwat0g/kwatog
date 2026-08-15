<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The inline validate() this replaces declared every relation as
 * `exists:table,id` while the SPA sends HashIDs, so a customer return created
 * from the UI failed validation on customer_id / invoice_id and any line's
 * product_id. It also never checked that the party matched the RMA type, so a
 * customer_return could be filed against a vendor with no customer at all,
 * leaving the credit-note step permanently unreachable.
 */
class StoreReturnRequestRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->can('return_management.manage') === true;
    }

    protected function hashIdFields(): array
    {
        return [
            'sales_order_id'                => SalesOrder::class,
            'invoice_id'                    => Invoice::class,
            'purchase_order_id'             => PurchaseOrder::class,
            'bill_id'                       => Bill::class,
            'customer_id'                   => Customer::class,
            'vendor_id'                     => Vendor::class,
            'items.*.product_id'            => Product::class,
            'items.*.item_id'               => Item::class,
            'items.*.source_sales_order_item_id' => SalesOrderItem::class,
            'items.*.source_invoice_item_id'     => InvoiceItem::class,
            'items.*.source_delivery_item_id'    => DeliveryItem::class,
            'items.*.source_po_item_id'     => PurchaseOrderItem::class,
            'items.*.source_grn_item_id'    => GrnItem::class,
            'items.*.source_bill_item_id'   => BillItem::class,
        ];
    }

    public function rules(): array
    {
        return [
            'type'                => ['required', Rule::enum(ReturnRequestType::class)],
            'sales_order_id'      => ['nullable', 'integer', 'exists:sales_orders,id'],
            'invoice_id'          => ['nullable', 'integer', 'exists:invoices,id'],
            'purchase_order_id'   => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'bill_id'             => ['nullable', 'integer', 'exists:bills,id'],
            'customer_id'         => ['nullable', 'integer', 'exists:customers,id'],
            'vendor_id'           => ['nullable', 'integer', 'exists:vendors,id'],
            'reason_code'         => ['nullable', 'string', 'max:30'],
            'reason_description'  => ['nullable', 'string', 'max:1000'],
            'finance_only'        => ['sometimes', 'boolean'],
            'finance_only_reason' => ['nullable', 'string', 'max:1000'],
            'customer_notes'      => ['nullable', 'string', 'max:2000'],
            'internal_notes'      => ['nullable', 'string', 'max:2000'],
            'resolution'          => ['nullable', 'string', 'max:30'],
            'return_date'         => ['nullable', 'date'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['nullable', 'integer', 'exists:products,id'],
            'items.*.item_id'     => ['nullable', 'integer', 'exists:items,id'],
            'items.*.quantity'    => ['required', 'decimal:0,3', 'min:0.001'],
            // The service rejects a null price, so require it here where the
            // error keys to the offending line instead of failing as a 422 with
            // no field association.
            'items.*.unit_price'  => ['required', 'decimal:0,2', 'min:0'],
            'items.*.reason'      => ['nullable', 'string', 'max:500'],
            'items.*.condition'   => ['nullable', 'string', 'max:30'],
            // These two were absent from the old rule set, so validate() stripped
            // them and every customer-return line lost its link back to the
            // originating SO / invoice line.
            'items.*.source_sales_order_item_id' => ['nullable', 'integer', 'exists:sales_order_items,id'],
            'items.*.source_invoice_item_id'     => ['nullable', 'integer', 'exists:invoice_items,id'],
            'items.*.source_delivery_item_id'    => ['nullable', 'integer', 'exists:delivery_items,id'],
            'items.*.lot_number'                  => ['nullable', 'string', 'max:120'],
            'items.*.serial_number'               => ['nullable', 'string', 'max:120'],
            'items.*.source_po_item_id'   => ['nullable', 'integer', 'exists:purchase_order_items,id'],
            'items.*.source_grn_item_id'  => ['nullable', 'integer', 'exists:grn_items,id'],
            'items.*.source_bill_item_id' => ['nullable', 'integer', 'exists:bill_items,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'             => 'A return needs at least one line item.',
            'items.min'                  => 'A return needs at least one line item.',
            'items.*.unit_price.required' => 'Each return line requires a unit price.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');

            if ($type === ReturnRequestType::CustomerReturn->value) {
                if (! $this->input('customer_id')) {
                    $validator->errors()->add('customer_id', 'A customer return requires a customer.');
                }
                if ($this->input('vendor_id')) {
                    $validator->errors()->add('vendor_id', 'A customer return cannot name a vendor.');
                }
            }

            if ($type === ReturnRequestType::SupplierReturn->value) {
                if (! $this->input('vendor_id')) {
                    $validator->errors()->add('vendor_id', 'A supplier return requires a vendor.');
                }
                if ($this->input('customer_id')) {
                    $validator->errors()->add('customer_id', 'A supplier return cannot name a customer.');
                }
            }

            foreach ((array) $this->input('items', []) as $index => $line) {
                if (empty($line['product_id']) && empty($line['item_id'])) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        'Each return line must reference a product or an inventory item.'
                    );
                }
                if ($type === ReturnRequestType::CustomerReturn->value
                    && !empty($line['item_id']) && !$this->boolean('finance_only')
                    && empty($line['source_invoice_item_id']) && empty($line['source_sales_order_item_id'])
                    && empty($line['source_delivery_item_id'])) {
                    $validator->errors()->add("items.{$index}.source_invoice_item_id", 'Stockable returns require invoice or sales-order line provenance.');
                }
            }
            if ($this->boolean('finance_only') && trim((string) $this->input('finance_only_reason')) === '') {
                $validator->errors()->add('finance_only_reason', 'Finance-only returns require an explicit non-stock reason.');
            }
        });
    }
}
