<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
                'type_label'     => $this->account->type?->label() ?? Str::headline((string) $this->account->type),
                'normal_balance' => $this->account->normal_balance?->value,
                'normal_balance_label' => $this->account->normal_balance?->label() ?? Str::headline((string) $this->account->normal_balance),
            ] : null),
        ];
    }
}
