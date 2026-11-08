<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('mrp.schedule') ?? false;
    }

    public function rules(): array
    {
        return [
            'scheduled_start' => ['required', 'date'],
            'expected_updated_at' => ['required', 'date'],
        ];
    }
}
