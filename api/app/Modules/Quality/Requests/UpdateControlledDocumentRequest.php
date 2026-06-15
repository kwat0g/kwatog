<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateControlledDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quality.documents.manage') === true;
    }

    public function rules(): array
    {
        $docId = $this->route('document')?->id;

        return [
            'code'                   => ['sometimes', 'string', 'max:40',
                Rule::unique('controlled_documents', 'code')->ignore($docId)],
            'title'                  => ['sometimes', 'string', 'max:200'],
            'category'               => ['sometimes', Rule::in(['sop', 'work_instruction', 'form', 'spec', 'policy'])],
            'description'            => ['nullable', 'string', 'max:5000'],
            'assignee_role'          => ['sometimes', 'string', Rule::in(Role::query()->pluck('slug')->all())],
            'review_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active'              => ['sometimes', 'boolean'],
        ];
    }
}
