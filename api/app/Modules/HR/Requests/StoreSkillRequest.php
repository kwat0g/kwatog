<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:120'],
            'category'    => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ];
    }
}
