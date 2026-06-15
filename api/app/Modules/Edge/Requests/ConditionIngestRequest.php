<?php

declare(strict_types=1);

namespace App\Modules\Edge\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T2.3 — Edge condition reading payload validator.
 *
 * Auth is gated upstream by `auth:edge_device` + `ability:edge:condition`,
 * so authorize() is a pass-through here.
 */
class ConditionIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'metric' => ['required', Rule::in([
                'temperature', 'vibration', 'pressure', 'current', 'oil_quality',
            ])],
            'value'           => ['required', 'numeric'],
            'unit'            => ['nullable', 'string', 'max:20'],
            'recorded_at'     => ['nullable', 'date'],
            'notes'           => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
