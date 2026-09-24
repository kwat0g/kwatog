<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use App\Modules\Purchasing\Requests\Concerns\SupplierQuoteRules;
use Illuminate\Foundation\Http\FormRequest;

/** Save (submit=false) or save-and-submit (submit=true) the supplier's one quotation. */
class StoreSupplierQuoteRequest extends FormRequest
{
    use SupplierQuoteRules;

    protected function prepareForValidation(): void
    {
        $this->decodeQuoteItems();
    }

    public function authorize(): bool
    {
        return auth('supplier_portal')->check();
    }

    public function rules(): array
    {
        return [
            'submit' => ['required', 'boolean'],
            ...$this->quoteRules(),
        ];
    }
}
