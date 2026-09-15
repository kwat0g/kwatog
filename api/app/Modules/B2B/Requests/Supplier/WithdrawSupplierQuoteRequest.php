<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawSupplierQuoteRequest extends FormRequest
{
    public function authorize(): bool { return auth('supplier_portal')->check(); }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:2000']]; }
}
