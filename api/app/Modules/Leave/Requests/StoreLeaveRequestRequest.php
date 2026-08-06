<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Common\Services\SettingsService;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leave.create') ?? false;
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
        $pastDays = $settings->requiredInt('leave.request.past_window_days', 0);
        $futureDays = $settings->requiredInt('leave.request.future_window_days', 0);
        $today = now()->startOfDay();
        return [
            'employee_id'   => ['required', 'string'],
            'leave_type_id' => ['required', 'string'],
            'start_date'    => ['required', 'date', 'after_or_equal:'.$today->copy()->subDays($pastDays)->toDateString(), 'before_or_equal:'.$today->copy()->addDays($futureDays)->toDateString()],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:'.$today->copy()->addDays($futureDays)->toDateString()],
            // M-18 half-day leave: nullable enum 'am'|'pm'.
            'half_day_period' => ['nullable', 'in:am,pm'],
            'reason'          => ['nullable', 'string', 'max:2000'],
            'document_path'   => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.after_or_equal'  => 'Start date is outside the configured request window.',
            'start_date.before_or_equal' => 'Start date is outside the configured request window.',
            'end_date.before_or_equal'   => 'End date is outside the configured request window.',
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id']   = Employee::tryDecodeHash($d['employee_id']);
        $d['leave_type_id'] = LeaveType::tryDecodeHash($d['leave_type_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        abort_if(!$d['leave_type_id'], 422, 'Invalid leave type.');
        return $d;
    }
}
