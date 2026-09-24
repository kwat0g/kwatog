<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class ShortClosePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('purchasing.po.create');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
