<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.grn.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_accepted_map' => ['nullable', 'array'],
        ];
    }
}
