<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class ApproveSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'purchasing.supplier_listings.review');
    }

    public function rules(): array
    {
        return [];
    }
}
