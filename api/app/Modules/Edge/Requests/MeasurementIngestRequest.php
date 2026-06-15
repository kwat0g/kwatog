<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T2.4 — Caliper / scale → POST /edge/v1/measurement payload.
 *
 * Hash IDs are validated as opaque strings here; the ingest service decodes
 * them and surfaces specific 422 keys (invalid_inspection / invalid_measurement).
 */
class MeasurementIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gated by auth:edge_device + ability:edge:measurement middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'inspection_id'   => ['required', 'string', 'max:100'],
            'measurement_id'  => ['required', 'string', 'max:100'],
            'measured_value'  => ['required', 'numeric'],
            'notes'           => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
