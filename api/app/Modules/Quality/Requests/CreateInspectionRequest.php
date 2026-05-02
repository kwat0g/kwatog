<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use Illuminate\Foundation\Http\FormRequest;
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
                InspectionEntityType::Grn->value       => GoodsReceiptNote::class,
                InspectionEntityType::WorkOrder->value => WorkOrder::class,
                default                                => null,
            };
            if ($modelClass) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
                $decoded = $modelClass::tryDecodeHash($hash);
                if ($decoded !== null) {
                    $this->merge(['entity_id' => $decoded]);
                }
            }
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return [
            'stage'          => ['required', Rule::in(InspectionStage::values())],
            'product_id'     => ['required', 'integer', 'exists:products,id'],
            'batch_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'entity_type'    => ['nullable', Rule::in(InspectionEntityType::values())],
            'entity_id'      => ['nullable', 'required_with:entity_type', 'integer', 'min:1'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ];
    }
}
