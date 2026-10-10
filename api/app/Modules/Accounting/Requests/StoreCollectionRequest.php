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
        return $this->user()?->hasPermission('accounting.invoices.collect_manual') ?? false;
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
     */
    public function rules(): array
    {
        return [
            'cash_account_id'  => ['required', 'string'],
            'collection_date'  => ['required', 'date'],
            'amount'           => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_AMOUNT],
            'payment_method'   => ['required', Rule::in(PaymentMethod::values())],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'manual_reason' => ['required', 'string', 'min:10', 'max:500'],
            'idempotency_key'  => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('manual_reason'))) $this->merge(['manual_reason' => trim($this->input('manual_reason'))]);
    }

    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) $validator->errors()->add('manual_reason', 'Only exceptional collection details can be submitted.');
        }];
    }
}
