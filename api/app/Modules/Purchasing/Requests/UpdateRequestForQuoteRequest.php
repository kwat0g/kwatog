<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequestForQuoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false; }
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'closes_at' => ['sometimes', 'date', 'after:now'],
        ];
    }
}
