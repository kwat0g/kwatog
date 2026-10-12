<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class BulkApproveSupplierListingRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one listing to approve.',
        ];
    }
}
