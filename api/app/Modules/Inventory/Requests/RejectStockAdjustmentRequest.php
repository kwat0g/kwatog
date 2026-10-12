<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class RejectStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'inventory.adjust.approve');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Enter a reason for rejecting this adjustment.',
        ];
    }
}
