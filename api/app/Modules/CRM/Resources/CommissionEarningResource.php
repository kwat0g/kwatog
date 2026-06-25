<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommissionEarningResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->hash_id,
            'sales_order'       => $this->whenLoaded('salesOrder', fn () => [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ]),
            'employee'          => $this->whenLoaded('employee', fn () => [
                'id'        => $this->employee->hash_id,
                'full_name' => $this->employee->first_name . ' ' . $this->employee->last_name,
            ]),
            'order_total'       => $this->order_total,
            'commission_rate'   => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'status'            => $this->status?->value,
            'approved_at'       => $this->approved_at?->toIso8601String(),
            'paid_at'           => $this->paid_at?->toIso8601String(),
            'period_start'      => $this->period_start?->toDateString(),
            'period_end'        => $this->period_end?->toDateString(),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
