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

    public function rules(): array
    {
        return [
            'date'                => ['sometimes', 'date'],
            'description'         => ['sometimes', 'string', 'max:500'],
            // Source provenance is immutable from the manual API boundary.
            'reference_type'      => ['prohibited'],
            'reference_id'        => ['prohibited'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'string'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
