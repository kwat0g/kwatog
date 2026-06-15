<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Either dept or HR approval — route middleware enforces the
        // specific permission (leave.approve_dept or leave.approve_hr).
        // We still validate the user is authenticated.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return ['remarks' => ['nullable', 'string', 'max:1000']];
    }
}
