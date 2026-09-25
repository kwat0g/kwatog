<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreMaterialReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.issue.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'quantity_returned' => ['required', 'decimal:0,3', 'min:0.001'],
            'expected_returned_quantity' => ['required', 'decimal:0,3', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key must contain only letters, numbers, dot, underscore, colon, or hyphen and be at most 128 characters.'],
            ]);
        }

        return $key;
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Please provide a reason of at least 10 characters for the unused-material return.',
        ];
    }
}
