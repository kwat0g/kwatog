<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetPurchaseRequestSourcingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create')
            || $this->user()?->hasPermission('purchasing.rfq.manage')
            || false;
    }

    public function rules(): array
    {
        return [
            'sourcing_method' => ['required', Rule::enum(PurchaseRequestSourcingMethod::class)],
        ];
    }
}
