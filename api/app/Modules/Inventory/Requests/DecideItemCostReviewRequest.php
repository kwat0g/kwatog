<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideItemCostReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.costs.approve') ?? false;
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['required', 'string', 'max:1000'], 'version' => ['required', 'integer', 'min:1']];
    }
}
