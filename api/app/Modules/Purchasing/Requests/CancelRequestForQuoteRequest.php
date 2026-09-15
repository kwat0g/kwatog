<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelRequestForQuoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false; }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:2000']]; }
}
