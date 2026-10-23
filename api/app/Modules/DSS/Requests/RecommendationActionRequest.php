<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecommendationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['accept', 'dismiss', 'snooze', 'execute'])],
            'reason' => ['required_if:action,dismiss', 'nullable', 'string', 'max:1000'],
            'snoozed_until' => ['required_if:action,snooze', 'nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Tell us why this recommendation does not apply.',
            'snoozed_until.required_if' => 'Choose when this recommendation should resurface.',
        ];
    }
}
