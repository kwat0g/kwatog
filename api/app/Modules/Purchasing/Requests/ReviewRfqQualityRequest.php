<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRfqQualityRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.quality_review') ?? false; }
    public function rules(): array { return ['compliance_status' => ['required', 'in:compliant,exception,blocking'], 'compliance_notes' => ['nullable', 'string', 'max:4000']]; }
}
