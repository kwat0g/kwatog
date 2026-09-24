<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

/** Same body as create, every field optional; invitations replace the set. */
class UpdateRequestForQuoteRequest extends StoreRequestForQuoteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['sometimes', 'string', 'max:200'],
            'closes_at' => ['sometimes', 'date', 'after:now'],
            'invitations' => ['sometimes', 'array', 'min:1'],
        ];
    }
}
