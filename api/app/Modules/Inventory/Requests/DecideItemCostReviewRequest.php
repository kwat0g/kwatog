<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideItemCostReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'inventory.costs.approve');
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['required', 'string', 'max:1000'], 'version' => ['required', 'integer', 'min:1']];
    }
}
