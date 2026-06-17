<?php

declare(strict_types=1);

namespace App\Modules\Landing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'full_name'        => ['required', 'string', 'max:150'],
            'company'          => ['required', 'string', 'max:150'],
            'email'            => ['required', 'string', 'email', 'max:150'],
            'part_description' => ['required', 'string', 'max:5000'],
            'annual_volume'    => ['nullable', 'integer', 'min:0'],
            'drawing'          => ['nullable', 'file', 'max:20480', 'extensions:pdf,step,stp,iges,igs,dwg,dxf,png,jpg,jpeg'],
        ];
    }
}
