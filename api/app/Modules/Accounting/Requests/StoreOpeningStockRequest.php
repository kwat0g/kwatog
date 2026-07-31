<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.opening_balance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            // Typed so an array payload can't reach OpeningBalanceService's
            // (string) cast and TypeError into a 500. HashIDs resolved there.
            'location_id'       => ['required', 'string'],
            'rows'              => ['required', 'array', 'min:1'],
            'rows.*.item_id'    => ['required', 'string'],
            'rows.*.quantity'   => ['required', 'numeric', 'gt:0'],
            'rows.*.unit_cost'  => ['required', 'numeric', 'min:0'],
        ];
    }
}
