<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Enums\BudgetTransferStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'transfer_number' => $this->transfer_number,
            'month' => $this->month,
            'amount' => (string) $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => BudgetTransferStatus::tryFrom((string) $this->status)?->label() ?? (string) $this->status,
            'from_line' => $this->whenLoaded('fromLine', fn () => [
                'id' => $this->fromLine?->hash_id,
                'account_code' => $this->fromLine?->account?->code,
                'account_name' => $this->fromLine?->account?->name,
                'annual_total' => $this->fromLine ? (string) $this->fromLine->annual_total : null,
                'budget_id' => $this->fromLine?->budget?->hash_id,
                'budget_name' => $this->fromLine?->budget?->name,
                'department' => $this->fromLine?->budget?->department?->name,
            ]),
            'to_line' => $this->whenLoaded('toLine', fn () => [
                'id' => $this->toLine?->hash_id,
                'account_code' => $this->toLine?->account?->code,
                'account_name' => $this->toLine?->account?->name,
                'annual_total' => $this->toLine ? (string) $this->toLine->annual_total : null,
                'budget_id' => $this->toLine?->budget?->hash_id,
                'budget_name' => $this->toLine?->budget?->name,
                'department' => $this->toLine?->budget?->department?->name,
            ]),
            'requested_by' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester?->hash_id,
                'name' => $this->requester?->name,
            ]),
            'approved_by' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver?->hash_id,
                'name' => $this->approver?->name,
            ]),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
