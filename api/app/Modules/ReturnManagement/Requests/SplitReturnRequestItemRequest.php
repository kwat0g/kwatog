<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use Illuminate\Foundation\Http\FormRequest;

class SplitReturnRequestItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $rma = $this->route('returnRequest');
        if ($rma instanceof ReturnRequest
            && $rma->type === ReturnRequestType::SupplierReturn
            && ReturnManagementAccess::customerReturnsOnly($user)) {
            return false;
        }

        return $user?->can('return_management.dispose') === true;
    }

    public function rules(): array
    {
        return ['split_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3']];
    }
}
