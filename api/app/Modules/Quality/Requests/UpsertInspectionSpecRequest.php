<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertInspectionSpecRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.specs.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id' => Product::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'                => ['required', 'integer', 'exists:products,id'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.parameter_name'    => ['required', 'string', 'max:150'],
            'items.*.parameter_type'    => ['required', Rule::in(['dimensional', 'visual', 'functional'])],
            'items.*.unit_of_measure'   => ['nullable', 'string', 'max:20'],
            'items.*.nominal_value'     => ['nullable', 'decimal:0,4'],
            'items.*.tolerance_min'     => ['nullable', 'decimal:0,4'],
            'items.*.tolerance_max'     => ['nullable', 'decimal:0,4'],
            'items.*.is_critical'       => ['nullable', 'boolean'],
            'items.*.sort_order'        => ['nullable', 'integer', 'min:0'],
            'items.*.notes'             => ['nullable', 'string', 'max:500'],
        ];
    }
}
