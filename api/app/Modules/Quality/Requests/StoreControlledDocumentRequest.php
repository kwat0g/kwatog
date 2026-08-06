<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Modules\Quality\Enums\DocumentCategory;

class StoreControlledDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quality.documents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'code'                   => ['required', 'string', 'max:40', 'unique:controlled_documents,code'],
            'title'                  => ['required', 'string', 'max:200'],
            'category'               => ['required', Rule::enum(DocumentCategory::class)],
            'description'            => ['nullable', 'string', 'max:5000'],
            'assignee_role'          => ['required', 'string', Rule::in(Role::query()->pluck('slug')->all())],
            'review_interval_months' => ['nullable', 'integer', 'min:1', 'max:'.app(\App\Common\Services\SettingsService::class)->requiredInt('quality.document.max_review_interval_months', 1, 600)],
            'is_active'              => ['nullable', 'boolean'],
        ];
    }
}
