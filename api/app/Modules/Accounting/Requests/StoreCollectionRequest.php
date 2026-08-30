<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCollectionRequest extends FormRequest
{
    /** `collections.amount` is decimal(15,2) — one centavo below 10^13. */
    public const MAX_AMOUNT = '9999999999999.99';

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.collect') ?? false;
    }

    /**
     * The centavo contract, enforced rather than silently applied — the same
     * change StoreJournalEntryRequest took, for the same column type. AR does
     * not reach that FormRequest: InvoiceService builds its own GL lines and
     * calls JournalEntryService::create() directly, so bare `numeric` here left
     * three measured behaviours in place:
     *   - `1.999` was accepted and stored as 2.00 via Money::round2();
     *   - `1e3` is numeric to PHP but not well-formed to BCMath, so it reached
     *     bccomp() and raised ValueError -> HTTP 500;
     *   - `99999999999999999.99` had no upper bound and hit the column as a
     *     22003 overflow -> HTTP 500.
     *
     * Note the SPA collection form uses z.coerce.number() with no precision
     * refinement (spa/src/pages/accounting/invoices/detail.tsx:38), so unlike
     * the journal-entry form it does not yet mirror this shape client-side; a
     * three-decimal entry now surfaces as a mapped 422 instead of a silent
     * round. Adding the client-side refinement is tracked separately.
     */
    public function rules(): array
    {
        return [
            'cash_account_id'  => ['required', 'string'],
            'collection_date'  => ['required', 'date'],
            'amount'           => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_AMOUNT],
            'payment_method'   => ['required', Rule::in(PaymentMethod::values())],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'idempotency_key'  => ['nullable', 'string', 'max:100'],
        ];
    }
}
