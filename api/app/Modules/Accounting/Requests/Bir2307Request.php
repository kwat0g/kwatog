<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Bir2307Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.bills.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'between:1,4'],
        ];
    }
}
