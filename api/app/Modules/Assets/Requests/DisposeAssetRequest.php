<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisposeAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.dispose');
    }

    public function rules(): array
    {
        return [
            'disposal_amount' => ['required', 'decimal:0,2', 'min:0'],
            'disposed_date'   => ['nullable', 'date'],
            'remarks'         => ['nullable', 'string', 'max:5000'],
        ];
    }
}
