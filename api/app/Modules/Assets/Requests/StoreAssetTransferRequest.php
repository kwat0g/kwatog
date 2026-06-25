<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.transfer');
    }

    public function rules(): array
    {
        return [
            'asset_id'           => ['required', 'integer', 'exists:assets,id'],
            'from_department_id' => ['required', 'integer', 'exists:departments,id'],
            'to_department_id'   => ['required', 'integer', 'exists:departments,id', 'different:from_department_id'],
            'reason'             => ['nullable', 'string', 'max:500'],
            'transfer_date'      => ['required', 'date'],
        ];
    }
}
