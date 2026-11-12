<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    use ResolvesHashIds {
        prepareForValidation as prepareHashIdsForValidation;
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.products.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['revenue_account_id' => Account::class];
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'part_number' => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9-]{2,30}$/', Rule::unique('products', 'part_number')->ignore($productId)],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_of_measure' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::exists('uoms', 'code')->whereNull('deleted_at'),
            ],
            'standard_cost' => ['sometimes', 'required', 'decimal:0,2', 'min:0'],
            'revenue_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('type', 'revenue')
                    ->where('is_active', true)),
            ],
            'base_selling_price' => ['prohibited'],
            'minimum_selling_price' => ['prohibited'],
            'is_active' => ['nullable', 'boolean'],
            'specifications' => ['sometimes', 'nullable', 'array:dimensions,material,finish,tolerances,notes'],
            'specifications.dimensions' => ['sometimes', 'nullable', 'array:length,width,height,unit'],
            'specifications.dimensions.length' => ['required_with:specifications.dimensions', 'numeric', 'gt:0', 'max:1000000'],
            'specifications.dimensions.width' => ['required_with:specifications.dimensions', 'numeric', 'gt:0', 'max:1000000'],
            'specifications.dimensions.height' => ['required_with:specifications.dimensions', 'numeric', 'gt:0', 'max:1000000'],
            'specifications.dimensions.unit' => ['required_with:specifications.dimensions', 'in:mm,cm,in'],
            'specifications.material' => ['nullable', 'string', 'max:160'],
            'specifications.finish' => ['nullable', 'string', 'max:160'],
            'specifications.tolerances' => ['nullable', 'string', 'max:255'],
            'specifications.notes' => ['nullable', 'string', 'max:2000'],
            'is_customizable' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareHashIdsForValidation();
        if ($this->has('part_number')) {
            $this->merge(['part_number' => strtoupper(trim((string) $this->input('part_number')))]);
        }
        if ($this->has('unit_of_measure')) {
            $this->merge(['unit_of_measure' => strtoupper(trim((string) $this->input('unit_of_measure')))]);
        }
    }
}
