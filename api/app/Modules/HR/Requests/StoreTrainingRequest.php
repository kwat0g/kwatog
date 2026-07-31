<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.trainings.manage');
    }

    protected function hashIdFields(): array
    {
        return [
            'department_id' => Department::class,
        ];
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string'],
            'duration_hours'   => ['nullable', 'numeric', 'min:0'],
            'validity_months'  => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_certification' => ['boolean'],
            'department_id'    => ['nullable', 'integer', 'exists:departments,id'],
            'is_active'        => ['boolean'],
        ];
    }
}
