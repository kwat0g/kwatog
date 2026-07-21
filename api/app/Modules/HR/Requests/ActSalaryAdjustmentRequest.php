<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REC-03 — approve or reject a salary adjustment. Requires the checker
 * permission; the ApprovalService additionally enforces the correct step role
 * and blocks the requester from acting on their own submission.
 */
class ActSalaryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.salary_adjustments.act');
    }

    public function rules(): array
    {
        return [
            'action'  => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000', 'required_if:action,reject'],
        ];
    }
}
