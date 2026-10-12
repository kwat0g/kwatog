<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class BulkRejectSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'purchasing.supplier_listings.review');
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one listing to reject.',
            'reason.required' => 'A rejection reason is required so the supplier knows what to correct.',
        ];
    }
}
