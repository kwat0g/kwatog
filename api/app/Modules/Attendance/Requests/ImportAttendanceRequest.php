<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.import') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv', 'max:5120'],
        ];
    }
}
