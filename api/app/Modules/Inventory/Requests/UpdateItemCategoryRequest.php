<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemCategoryRequest extends FormRequest
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
        $id = $this->route('itemCategory')?->id;

        return [
            'name'      => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            // Prevent making a category its own parent.
            'parent_id' => ['nullable', 'integer', 'exists:item_categories,id', $id ? 'not_in:' . $id : ''],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'A category cannot be its own parent.',
            'parent_id.exists' => 'Selected parent category no longer exists.',
        ];
    }
}
