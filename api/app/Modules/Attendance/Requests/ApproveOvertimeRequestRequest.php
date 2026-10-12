<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Common\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

class ApproveOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ApprovalDelegation::canActForPermission($this->user(), 'attendance.ot.approve');
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
