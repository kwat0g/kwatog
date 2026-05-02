<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'line_no'     => (int) $this->line_no,
            'debit'       => (string) $this->debit,
            'credit'      => (string) $this->credit,
            'description' => $this->description,
            'account'     => $this->whenLoaded('account', fn () => $this->account ? [
                'id'             => $this->account->hash_id,
                'code'           => $this->account->code,
                'name'           => $this->account->name,
                'type'           => $this->account->type?->value,
                'normal_balance' => $this->account->normal_balance?->value,
            ] : null),
        ];
    }
}
