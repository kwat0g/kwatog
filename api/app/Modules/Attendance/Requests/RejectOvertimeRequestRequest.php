<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.ot.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
