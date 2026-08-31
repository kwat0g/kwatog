<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.grn.create') ?? false;
    }

    /**
     * `item_accepted_map` used to be validated as `['nullable','array']` and
     * nothing else, so its VALUES reached GrnService::partialAccept() raw.
     * bccomp() there raises a ValueError on '1e3' / '1e17' / '1e20' (is_numeric
     * says yes, BCMath says not well-formed), which no controller arm catches —
     * measured as HTTP 500. And '1.9999' stored silently as 2.000 in the
     * numeric(15,3) column, i.e. the system accepted a different quantity than
     * the one submitted. The keys stay unvalidated on purpose: they are GrnItem
     * HashIDs and the controller decodes them, skipping undecodable entries.
     */
    public function rules(): array
    {
        return [
            'item_accepted_map' => ['nullable', 'array'],
            'item_accepted_map.*' => ['required', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
        ];
    }
}
