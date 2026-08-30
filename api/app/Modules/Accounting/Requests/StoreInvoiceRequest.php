<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\VatClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    /** Money columns on invoices/invoice_items are decimal(15,2). */
    public const MAX_AMOUNT = '9999999999999.99';

    /** `invoice_items.quantity` is decimal(12,2), not (15,2). */
    public const MAX_QUANTITY = '9999999999.99';

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id'                  => ['required', 'string'],
            'date'                         => ['required', 'date'],
            'due_date'                     => ['nullable', 'date', 'after_or_equal:date'],
            'is_vatable'                   => ['nullable', 'boolean'],
            'remarks'                      => ['nullable', 'string', 'max:1000'],
            // InvoiceService::create() persists these; without rules validated()
            // stripped them, so the SO/Delivery link (which finalize() needs to
            // promote the parent SO to 'invoiced') and every BIR field on the
            // invoice were silently unreachable from the SPA.
            // HashIDs resolved in the service, hence 'string' not 'integer'.
            'sales_order_id'               => ['nullable', 'string'],
            'delivery_id'                  => ['nullable', 'string'],
            'lifecycle_type'              => ['nullable', 'string', Rule::in(['standard', 'prebill'])],
            'prebill_reason'              => ['required_if:lifecycle_type,prebill', 'string', 'max:1000'],
            // Rule::in keeps VatClassification::from() off the 500 path — an
            // unknown string there is an uncatchable \ValueError. Also the only
            // way the SPA can reach zero_rated (export/PEZA) sales.
            'vat_classification'           => ['nullable', 'string', Rule::in(VatClassification::values())],
            // 'numeric' keeps an array payload out of normalizeDiscount(), whose
            // string|float|int|null signature would TypeError into a 500.
            // 'decimal:0,2' + 'max' complete the centavo contract: see the note
            // on items.*.unit_price below.
            'senior_pwd_discount'          => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            // max: mirrors migration 0207's column widths — an over-long value
            // otherwise reaches PG as SQLSTATE[22001] (a 500, not a 422).
            'buyer_tin'                    => ['nullable', 'string', 'max:20'],
            'atp_number'                   => ['nullable', 'string', 'max:50'],
            'serial_range'                 => ['nullable', 'string', 'max:50'],
            'is_original'                  => ['nullable', 'boolean'],
            'items'                        => ['required', 'array', 'min:1'],
            'items.*.revenue_account_id'   => ['required', 'string'],
            'items.*.source_delivery_item_id' => ['nullable', 'string'],
            'items.*.description'          => ['required', 'string', 'max:200'],
            // The centavo contract, enforced rather than silently applied — the
            // same change StoreJournalEntryRequest took, for the same column
            // type. AR never reaches that FormRequest: InvoiceService builds its
            // own GL lines and calls JournalEntryService::create() directly, so
            // bare 'numeric' here left three measured behaviours in place, all
            // reproduced through POST /api/v1/invoices:
            //   - '1.999' returned 201 with subtotal 2.00 — the operator was
            //     told it saved, with a figure they never entered;
            //   - '1e3' is numeric to PHP but not well-formed to BCMath, so it
            //     reached bccomp() and raised ValueError -> HTTP 500;
            //   - '99999999999999999.99' had no upper bound and hit the column
            //     as SQLSTATE[22003] -> HTTP 500.
            // 'decimal:0,2' rejects over-precision and scientific notation (its
            // regex has no exponent branch); 'max' bounds the column. The
            // delivery -> invoice handoff calls InvoiceService::create()
            // directly (DeliveryService.php:1164) and so is unaffected.
            'items.*.quantity'             => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_QUANTITY],
            'items.*.unit'                 => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'           => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }
}
