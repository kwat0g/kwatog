<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class RejectSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'purchasing.supplier_listings.review');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required so the supplier knows what to correct.',
        ];
    }
}
