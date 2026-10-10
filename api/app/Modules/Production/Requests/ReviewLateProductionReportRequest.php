<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewLateProductionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return (bool) ($actor?->is_active && $actor->hasPermission('production.work_orders.lifecycle')
            && $actor->hasPermission('production.wo.record'));
    }

    public function rules(): array
    {
        return ['review_reason' => ['nullable', 'string', 'max:500']];
    }
}
