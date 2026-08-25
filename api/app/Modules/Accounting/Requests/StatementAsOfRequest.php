<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StatementAsOfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function asOfDate(): Carbon
    {
        $value = $this->input('as_of');
        if (! is_string($value) || $value === '') {
            return now();
        }

        return Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
    }
}
