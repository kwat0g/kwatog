<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OutputIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by route middleware: auth:edge_device + ability:edge:output
    }

    public function rules(): array
    {
        return [
            'good_count'               => ['required', 'integer', 'min:0'],
            'reject_count'             => ['nullable', 'integer', 'min:0'],
            'shift'                    => ['nullable', 'string', 'max:20'],
            'remarks'                  => ['nullable', 'string', 'max:500'],
            'defects'                  => ['nullable', 'array'],
            'defects.*.defect_type_id' => ['required_with:defects.*.count', 'string', 'max:100'],
            'defects.*.count'          => ['required_with:defects.*.defect_type_id', 'integer', 'min:0'],
            'idempotency_key'          => ['nullable', 'string', 'max:120'],
        ];
    }
}
