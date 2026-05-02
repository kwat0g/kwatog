<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'string'],
            'date'             => ['required', 'date', 'after_or_equal:'.now()->subDays(60)->toDateString(), 'before_or_equal:'.now()->addDays(30)->toDateString()],
            'hours_requested'  => ['required', 'numeric', 'min:0.5', 'max:8'],
            'reason'           => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.after_or_equal'  => 'OT date cannot be older than 60 days.',
            'date.before_or_equal' => 'OT date cannot be more than 30 days ahead.',
            'hours_requested.max'  => 'OT cannot exceed 8 hours per day.',
            'reason.min'           => 'Please provide a meaningful reason (at least 5 characters).',
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id'] = Employee::tryDecodeHash($d['employee_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        return $d;
    }
}
