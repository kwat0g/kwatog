<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Requests\Concerns\SupplierQuoteRules;
use Illuminate\Foundation\Http\FormRequest;

/** A phone or email quotation entered by the buyer for an invited supplier. */
final class ManualCaptureRfqQuoteRequest extends FormRequest
{
    use SupplierQuoteRules;

    protected function prepareForValidation(): void
    {
        $this->decodeQuoteItems();
        $vendor = $this->input('vendor_id');
        $this->merge(['vendor_id' => $vendor !== null ? (HashIdFilter::decode((string) $vendor, Vendor::class) ?? 0) : null]);
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            ...$this->quoteRules(),
        ];
    }
}
