<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use App\Common\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderAdvanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'sales_order_id' => $this->salesOrder?->hash_id,
            'sales_order_number' => $this->salesOrder?->so_number,
            'customer_id' => $this->customer?->hash_id,
            'customer_name' => $this->customer?->name,
            'attempt_number' => (int) $this->attempt_number,
            'order_total_snapshot' => (string) $this->order_total_snapshot,
            'required_amount' => (string) $this->required_amount,
            'percentage' => $this->percentage === null ? null : (string) $this->percentage,
            'submitted_amount' => $this->submitted_amount === null ? null : (string) $this->submitted_amount,
            'refunded_amount' => (string) $this->refunded_amount,
            'unapplied_amount' => $this->whenLoaded('applications', fn (): string => Money::sub(
                Money::sub((string) ($this->submitted_amount ?? '0'), Money::add(...$this->applications->pluck('amount')->map(static fn ($amount): string => (string) $amount)->all())),
                (string) $this->refunded_amount,
            )),
            'status' => (string) $this->status,
            'sending_bank' => $this->sending_bank,
            'reference_number' => $this->reference_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'proof_file_name' => $this->proof_file_name,
            'review_reason' => $this->review_reason,
            'refund_reference' => $this->refund_reference,
            'refund_reason' => $this->refund_reason,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
