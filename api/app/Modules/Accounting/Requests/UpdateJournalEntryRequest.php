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
            'reference_type'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'reference_id'        => ['sometimes', 'nullable', 'integer'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'string'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
