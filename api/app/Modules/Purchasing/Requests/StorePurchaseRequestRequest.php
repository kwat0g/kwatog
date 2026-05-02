<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Department;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.pr.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'department_id'   => Department::class,
            'items.*.item_id' => Item::class,
        ];
    }

    public function rules(): array
    {
        return [
            'department_id'                => ['nullable', 'integer', 'exists:departments,id'],
            'date'                         => ['nullable', 'date'],
            'reason'                       => ['nullable', 'string', 'max:1000'],
            'priority'                     => ['nullable', Rule::in(PurchaseRequestPriority::values())],
            'items'                        => ['required', 'array', 'min:1'],
            'items.*.item_id'              => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description'          => ['required', 'string', 'min:2', 'max:200'],
            'items.*.quantity'             => ['required', 'decimal:0,2', 'min:0.01'],
            'items.*.unit'                 => ['nullable', 'string', 'max:20'],
            'items.*.estimated_unit_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.purpose'              => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A purchase request must have at least one line.',
        ];
    }
}
