<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $application = $this->route('jobApplication');
        if ($this->input('action') === 'advance'
            && $application instanceof JobApplication
            && $application->stage === ApplicationStage::Offer) {
            return $user->hasPermission('hr.recruitment.hire');
        }

        return $user->hasPermission('hr.recruitment.applications');
    }

    public function rules(): array
    {
        $rules = [
            'action' => ['required', 'in:advance,reject'],
            'expected_stage' => ['sometimes', Rule::enum(ApplicationStage::class)],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
        ];

        $application = $this->route('jobApplication');
        $stageForValidation = $this->input('expected_stage')
            ?? ($application instanceof JobApplication ? $application->stage->value : null);
        if ($this->input('action') === 'advance' && $stageForValidation === ApplicationStage::Screening->value) {
            $rules['interview'] = ['required', 'array'];
            $rules['interview.scheduled_at'] = ['required', 'date', 'after:now'];
            $rules['interview.location'] = ['nullable', 'string', 'max:200'];
            $rules['interview.interviewer_name'] = ['required', 'string', 'max:200'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'interview.required' => 'An interview must be scheduled when moving to the interview stage.',
            'interview.scheduled_at.required' => 'Interview date and time is required.',
            'interview.scheduled_at.after' => 'Interview must be scheduled in the future.',
            'interview.interviewer_name.required' => 'Interviewer name is required.',
        ];
    }
}
