<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Foundation\Http\FormRequest;

class StoreItemCategoryRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.items.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['parent_id' => ItemCategory::class];
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'min:2', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:item_categories,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required.',
            'name.min'      => 'Category name must be at least 2 characters.',
            'parent_id.exists' => 'Selected parent category no longer exists.',
        ];
    }
}
