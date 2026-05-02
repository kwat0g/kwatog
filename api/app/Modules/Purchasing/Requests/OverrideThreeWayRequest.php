<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideThreeWayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.approve') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:20', 'max:1000']];
    }
}
