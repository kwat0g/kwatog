<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRecommendationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
            'domain' => ['nullable', 'string', 'max:50'],
            'owner_role' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'snoozed', 'accepted', 'executed', 'dismissed', 'resolved', 'expired'])],
            'search' => ['nullable', 'string', 'max:120'],
            'detector_id' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['priority_score', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
