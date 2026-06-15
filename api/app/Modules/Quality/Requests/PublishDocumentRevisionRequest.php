<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishDocumentRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quality.documents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date'],
            'change_reason'  => ['required', 'string', 'max:2000'],
            // 10 MB cap; tweak if needed in deployment config.
            'file'           => ['required', 'file', 'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ];
    }
}
