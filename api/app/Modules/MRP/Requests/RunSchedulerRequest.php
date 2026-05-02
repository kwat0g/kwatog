<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;

class RunSchedulerRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('mrp.schedule') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['work_order_ids.*' => WorkOrder::class];
    }

    public function rules(): array
    {
        return [
            'work_order_ids'   => ['nullable', 'array'],
            'work_order_ids.*' => ['integer', 'exists:work_orders,id'],
        ];
    }
}
