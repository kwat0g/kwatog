<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Department;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Models\PurchaseRequestTemplate;
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
            'department_id' => Department::class,
            'template_id' => PurchaseRequestTemplate::class,
            'mrp_plan_id' => MrpPlan::class,
            'items.*.item_id' => Item::class,
        ];
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'template_id' => ['nullable', 'integer', 'exists:purchase_request_templates,id'],
            // Optional provenance link to the MRP run that motivated this
            // request (trace §7.4). Resolved from a HashID by hashIdFields().
            'mrp_plan_id' => ['nullable', 'integer', 'exists:mrp_plans,id'],
            'date' => ['nullable', 'date'],
            'required_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', Rule::in(PurchaseRequestPriority::values())],
            // RFQ PRs approve the need only; money is approved on the PO.
            'sourcing_method' => ['required', Rule::in(array_column(PurchaseRequestSourcingMethod::cases(), 'value'))],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description' => ['required', 'string', 'min:2', 'max:200'],
            'items.*.quantity' => ['required', 'decimal:0,3', 'min:0.001', 'max:999999999.999'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.estimated_unit_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.purpose' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A purchase request must have at least one line.',
            'sourcing_method.in' => 'Choose Direct PO or Competitive RFQ.',
        ];
    }
}
