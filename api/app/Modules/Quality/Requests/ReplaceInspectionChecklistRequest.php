<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceInspectionChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000'], 'expected_result_version' => ['required', 'integer', 'min:0']];
    }
}
