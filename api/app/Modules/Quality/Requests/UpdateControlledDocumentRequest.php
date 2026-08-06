<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Modules\Quality\Enums\DocumentCategory;

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
            'category'               => ['sometimes', Rule::enum(DocumentCategory::class)],
            'description'            => ['nullable', 'string', 'max:5000'],
            'assignee_role'          => ['sometimes', 'string', Rule::in(Role::query()->pluck('slug')->all())],
            'review_interval_months' => ['nullable', 'integer', 'min:1', 'max:'.app(\App\Common\Services\SettingsService::class)->requiredInt('quality.document.max_review_interval_months', 1, 600)],
            'is_active'              => ['sometimes', 'boolean'],
        ];
    }
}
