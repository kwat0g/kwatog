<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DriverUploadReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'max:8192'], // 8 MB
        ];
    }
}
