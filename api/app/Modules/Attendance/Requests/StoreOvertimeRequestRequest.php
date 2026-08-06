<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Common\Services\SettingsService;
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
        $settings = app(SettingsService::class);
        $pastDays = $settings->requiredInt('attendance.ot.request_past_days', 0);
        $futureDays = $settings->requiredInt('attendance.ot.request_future_days', 0);
        $minHours = $settings->requiredFloat('attendance.ot.request_min_hours', 0.01);
        $maxHours = $settings->requiredFloat('attendance.ot.admin_max_hours', $minHours);
        $today = now()->startOfDay();
        return [
            'employee_id'      => ['required', 'string'],
            'date'             => ['required', 'date', 'after_or_equal:'.$today->copy()->subDays($pastDays)->toDateString(), 'before_or_equal:'.$today->copy()->addDays($futureDays)->toDateString()],
            'hours_requested'  => ['required', 'numeric', 'min:'.$minHours, 'max:'.$maxHours],
            'reason'           => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.after_or_equal'  => 'OT date is outside the configured request window.',
            'date.before_or_equal' => 'OT date is outside the configured request window.',
            'hours_requested.max'  => 'OT exceeds the configured daily limit.',
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
