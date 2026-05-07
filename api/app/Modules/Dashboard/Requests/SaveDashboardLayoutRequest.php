<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Series R — Task R4. Body shape for PUT /dashboard/layout.
 *
 *   { widgets: [{ key, x?, y?, w?, h? }, ...] }
 */
class SaveDashboardLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'widgets'        => ['required', 'array'],
            'widgets.*.key'  => ['required', 'string', 'max:100'],
            'widgets.*.x'    => ['sometimes', 'integer', 'min:0', 'max:255'],
            'widgets.*.y'    => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'widgets.*.w'    => ['sometimes', 'integer', 'min:1', 'max:24'],
            'widgets.*.h'    => ['sometimes', 'integer', 'min:1', 'max:24'],
        ];
    }
}
