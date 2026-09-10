<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAssetDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces assets.dispose.approve; this keeps the
        // request itself honest for non-HTTP callers.
        return (bool) $this->user()?->hasPermission('assets.dispose.approve');
    }

    public function rules(): array
    {
        return ['remarks' => ['nullable', 'string', 'max:1000']];
    }
}
