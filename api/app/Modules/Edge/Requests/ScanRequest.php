<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already guarded by `auth:edge_device` + `ability:edge:scan`.
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode'          => ['required', 'string', 'max:255'],
            'context'          => ['nullable', 'array'],
            'context.wo_id'    => ['nullable', 'string', 'max:100'],
            'context.po_id'    => ['nullable', 'string', 'max:100'],
            'context.grn_id'   => ['nullable', 'string', 'max:100'],
            'context.location' => ['nullable', 'string', 'max:100'],
        ];
    }
}
