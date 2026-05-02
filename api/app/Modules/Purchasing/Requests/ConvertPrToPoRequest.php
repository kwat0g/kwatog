<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertPrToPoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    public function rules(): array
    {
        return [
            // map: { pr_item_id => vendor_id }
            'vendor_map'   => ['required', 'array', 'min:1'],
            'vendor_map.*' => ['required', 'integer'],
        ];
    }
}
