<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.journal.create') ?? false;
    }

    /** Same centavo contract as {@see StoreJournalEntryRequest::rules()}. */
    public function rules(): array
    {
        $amount = [
            'nullable', 'numeric', 'decimal:0,2', 'min:0',
            'max:'.StoreJournalEntryRequest::MAX_LINE_AMOUNT,
        ];

        return [
            'date'                => ['sometimes', 'date'],
            'description'         => ['sometimes', 'string', 'max:500'],
            // Source provenance is immutable from the manual API boundary.
            'reference_type'      => ['prohibited'],
            'reference_id'        => ['prohibited'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'string'],
            'lines.*.debit'       => $amount,
            'lines.*.credit'      => $amount,
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
