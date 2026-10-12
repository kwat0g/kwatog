<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The metric dictionary is readable by any authenticated user — but only the
 * metrics whose gate they hold come back. Authorizing the *endpoint* per
 * permission would be wrong here: there is no single permission that describes
 * "may read the dictionary", and the service filters row by row (the same rule
 * as `GET /dashboards/badges` and the widget picker).
 */
class ListMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'module' => ['nullable', 'string', 'max:40'],
        ];
    }
}
