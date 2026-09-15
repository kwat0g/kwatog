<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRfqAddendumRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false; }
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'], 'material_change' => ['boolean'], 'extension_days' => ['nullable', 'integer', 'min:1', 'max:30']];
    }
}
