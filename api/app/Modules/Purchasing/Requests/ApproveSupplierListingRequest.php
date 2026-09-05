<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveSupplierListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.supplier_listings.review') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
