<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CreateInspectionRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id' => Product::class,
            'work_order_output_id' => WorkOrderOutput::class,
            // entity_id is decoded conditionally — see prepareForValidation override.
        ];
    }

    protected function prepareForValidation(): void
    {
        // Resolve the conditional polymorphic entity reference before the
        // base trait runs over the static map.
        $type = $this->input('entity_type');
        $hash = $this->input('entity_id');
        if (is_string($hash) && $type) {
            $modelClass = match ($type) {
                InspectionEntityType::Grn->value => GoodsReceiptNote::class,
                InspectionEntityType::WorkOrder->value => WorkOrder::class,
                default => null,
            };
            if ($modelClass) {
                /** @var class-string<Model> $modelClass */
                $decoded = $modelClass::tryDecodeHash($hash);
                if ($decoded !== null) {
                    $this->merge(['entity_id' => $decoded]);
                }
            }
        }

        $outputHash = $this->input('work_order_output_id');
        if (is_string($outputHash) && $outputHash !== '') {
            $decoded = WorkOrderOutput::tryDecodeHash($outputHash);
            if ($decoded !== null) {
                $this->merge(['work_order_output_id' => $decoded]);
            }
        }

        // Run the ResolvesHashIds trait's decoder for the static map
        // (`product_id` etc). Overriding prepareForValidation in a subclass
        // hides the trait's own implementation, so we re-run its logic here.
        $fields = $this->hashIdFields();
        if (! empty($fields)) {
            $payload = $this->all();
            $changed = false;
            foreach ($fields as $path => $modelClass) {
                $value = data_get($payload, $path);
                if ($value === null || $value === '') {
                    continue;
                }
                $decoded = HashIdFilter::decode($value, $modelClass);
                Arr::set($payload, $path, $decoded);
                $changed = true;
            }
            if ($changed) {
                $this->merge($payload);
            }
        }
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(InspectionStage::values())],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'batch_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'entity_type' => ['nullable', Rule::in(InspectionEntityType::values())],
            'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'],
            'work_order_output_id' => ['nullable', 'integer', 'exists:work_order_outputs,id', 'required_if:stage,outgoing'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
