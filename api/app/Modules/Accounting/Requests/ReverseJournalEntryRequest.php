<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.journal.reverse') ?? false;
    }

    public function rules(): array
    {
        return [
            'reverse_date' => ['nullable', 'date'],
        ];
    }
}
