<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.ot.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
