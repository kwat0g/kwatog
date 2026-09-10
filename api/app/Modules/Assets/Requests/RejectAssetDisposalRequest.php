<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectAssetDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('assets.dispose.approve');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
