<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wo = $this->route('workOrder');

        return ($this->user()?->hasPermission('production.work_orders.lifecycle') ?? false)
            || ($wo?->production_purpose === 'stock' && $wo->parent_wo_id === null
                && ($this->user()?->hasPermission('production.wo.cancel_stock') ?? false));
    }

    public function rules(): array
    {
        return [
            'reason' => [$this->route('workOrder')?->production_purpose === 'stock' ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
