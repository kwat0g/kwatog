<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.applications');
    }

    public function rules(): array
    {
        return [
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'location'         => ['nullable', 'string', 'max:200'],
            'interviewer_name' => ['required', 'string', 'max:200'],
        ];
    }
}
