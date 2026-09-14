<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use Illuminate\Validation\Rule;

/** Draft edits use the exact same hash-ID and provenance validation as create. */
class UpdateReturnRequestRequest extends StoreReturnRequestRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['type'] = ['sometimes', Rule::enum(ReturnRequestType::class)];
        $rules['items'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
