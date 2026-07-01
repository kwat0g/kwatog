<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\SpcChartType;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpcChartRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.spc.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'   => Product::class,
            'spec_item_id' => InspectionSpecItem::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'    => ['required', 'integer', 'exists:products,id'],
            'spec_item_id'  => ['required', 'integer', 'exists:inspection_spec_items,id'],
            'chart_type'    => ['required', Rule::in(array_column(SpcChartType::cases(), 'value'))],
            'subgroup_size' => ['sometimes', 'integer', 'min:2', 'max:10'],
        ];
    }
}
