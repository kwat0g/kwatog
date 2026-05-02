<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmploymentHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'change_type'    => $this->change_type,
            'from_value'     => $this->from_value,
            'to_value'       => $this->to_value,
            'effective_date' => optional($this->effective_date)->toDateString(),
            'remarks'        => $this->remarks,
            'approver'       => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->hash_id, 'name' => $this->approver->name,
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
        ];
    }
}
