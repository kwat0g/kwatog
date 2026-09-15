<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtendRequestForQuoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false; }
    public function rules(): array { return ['closes_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:2000']]; }
}
