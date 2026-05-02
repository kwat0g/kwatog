<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('loans.approve') ?? false; }
    public function rules(): array { return ['remarks' => ['nullable', 'string', 'max:1000']]; }
}
