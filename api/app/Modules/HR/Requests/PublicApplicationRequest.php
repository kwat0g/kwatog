<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $postingId = $this->route('jobPosting')?->id;

        return [
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'email'        => [
                'required', 'email', 'max:255',
                Rule::unique('job_applications')->where('job_posting_id', $postingId),
            ],
            'phone'        => ['required', 'string', 'max:30'],
            'resume'       => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'You have already applied for this position.',
        ];
    }
}
