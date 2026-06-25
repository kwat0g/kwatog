<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisposeReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return_management.manage');
    }

    public function rules(): array
    {
        return [
            'dispositions'               => ['required', 'array', 'min:1'],
            'dispositions.*.item_id'     => ['required', 'string'],
            'dispositions.*.disposition' => ['required', 'string', 'in:scrap,rework,restock,return_to_supplier'],
            'dispositions.*.notes'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
