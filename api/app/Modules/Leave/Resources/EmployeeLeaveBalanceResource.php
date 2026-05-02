<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'employee_id'    => optional($this->employee)->hash_id,
            'leave_type'     => $this->whenLoaded('leaveType', fn () => [
                'id'   => $this->leaveType->hash_id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
            ]),
            'year'           => (int) $this->year,
            'total_credits'  => (string) $this->total_credits,
            'used'           => (string) $this->used,
            'remaining'      => (string) $this->remaining,
        ];
    }
}
