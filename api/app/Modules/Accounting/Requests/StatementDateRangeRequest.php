<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StatementDateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->parseDate('from') ?? now()->startOfMonth();
            $to = $this->parseDate('to') ?? now()->endOfMonth();

            if ($from->gt($to)) {
                $validator->errors()->add('to', 'The to date must be on or after the from date.');
            }
        });
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        $from = $this->parseDate('from') ?? now()->startOfMonth();
        $to = $this->parseDate('to') ?? now()->endOfMonth();

        return [$from, $to];
    }

    private function parseDate(string $key): ?Carbon
    {
        $value = $this->input($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof Carbon && $date->format('Y-m-d') === $value ? $date : null;
    }
}
