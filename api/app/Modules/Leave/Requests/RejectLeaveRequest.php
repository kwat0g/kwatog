<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The service enforces the permission for the request's active stage.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
