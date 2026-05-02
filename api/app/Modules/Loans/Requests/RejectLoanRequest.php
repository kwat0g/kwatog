<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectLoanRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('loans.approve') ?? false; }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:1000']]; }
}
