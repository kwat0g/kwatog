<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer self-service RMA intake.
 *
 * The portal sends only a source line (invoice / sales-order / delivery) and a
 * quantity per returned line. The product, the finished-goods inventory item
 * and the unit price are all resolved server-side by the return service, so
 * none of them is accepted from the client.
 */
class StoreCustomerReturnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        // Route is behind auth:customer_portal + B2BTenancyScopeMiddleware; the
        // service pins customer_id and re-checks source ownership.
        return true;
    }

    protected function hashIdFields(): array
    {
        return [
            'items.*.source_invoice_item_id'     => InvoiceItem::class,
            'items.*.source_sales_order_item_id' => SalesOrderItem::class,
            'items.*.source_delivery_item_id'    => DeliveryItem::class,
        ];
    }

    public function rules(): array
    {
        return [
            'reason_code'        => ['nullable', 'string', 'max:30'],
            'reason_description' => ['nullable', 'string', 'max:1000'],
            'customer_notes'     => ['nullable', 'string', 'max:2000'],
            'return_date'        => ['nullable', 'date'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.quantity'   => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.reason'     => ['nullable', 'string', 'max:500'],
            'items.*.condition'  => ['nullable', 'string', 'max:30'],
            'items.*.source_invoice_item_id'     => ['nullable', 'integer', 'exists:invoice_items,id'],
            'items.*.source_sales_order_item_id' => ['nullable', 'integer', 'exists:sales_order_items,id'],
            'items.*.source_delivery_item_id'    => ['nullable', 'integer', 'exists:delivery_items,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A return needs at least one line item.',
            'items.min'      => 'A return needs at least one line item.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $line) {
                $sources = collect([
                    $line['source_invoice_item_id'] ?? null,
                    $line['source_sales_order_item_id'] ?? null,
                    $line['source_delivery_item_id'] ?? null,
                ])->filter()->count();

                if ($sources !== 1) {
                    $validator->errors()->add(
                        "items.{$index}.source_invoice_item_id",
                        'Each returned line must reference exactly one invoice, sales-order, or delivery line.',
                    );
                }
            }
        });
    }
}
