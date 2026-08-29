<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJournalEntryRequest extends FormRequest
{
    /**
     * decimal(15,2) tops out one centavo below 10^13, so this is the largest
     * amount a journal line can actually hold. Above it PostgreSQL raised 22003
     * and the operator got a 500.
     */
    public const MAX_LINE_AMOUNT = '9999999999999.99';

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.journal.create') ?? false;
    }

    /**
     * The centavo contract, enforced rather than silently applied.
     *
     * `numeric|min:0` accepted anything `is_numeric()` liked and the service
     * then ran it through `Money::round2()`, so three measured behaviours all
     * came from this one rule:
     *   - `1.999` was accepted and stored as `2.00` — the operator was told the
     *     entry succeeded, with a figure they never entered;
     *   - `1e3` is numeric to PHP but not well-formed to BCMath, so it reached
     *     `bccomp()` and raised ValueError -> HTTP 500;
     *   - `99999999999999999.00` passed with no upper bound and hit the column
     *     as a 22003 overflow -> HTTP 500.
     *
     * `decimal:0,2` rejects both over-precision and scientific notation (its
     * regex has no exponent branch), and `max` bounds the column. The SPA
     * already enforces the same shape client-side with /^\d+(\.\d{1,2})?$/, so
     * this closes the gap between the two entry points rather than adding a
     * rule the UI does not know about.
     */
    public function rules(): array
    {
        $amount = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_LINE_AMOUNT];

        return [
            'date'              => ['required', 'date'],
            'description'       => ['required', 'string', 'max:500'],
            // Source provenance belongs to trusted automated writers, never to
            // the manual maker/checker form.
            'reference_type'    => ['prohibited'],
            'reference_id'      => ['prohibited'],
            'lines'             => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'string'],
            'lines.*.debit'     => $amount,
            'lines.*.credit'    => $amount,
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
