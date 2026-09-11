<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

class RejectDeliveryScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
