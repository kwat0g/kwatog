<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Task SS2 — Finance leg of a bank-account change request. Authorized by a
 * dedicated permission so a Finance Officer (who lacks hr.employees.edit) can
 * still sign off on the disbursement-relevant change.
 */
class FinanceReviewProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.profile_updates.finance_review') ?? false;
    }

    public function rules(): array
    {
        return [
            'action'  => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
