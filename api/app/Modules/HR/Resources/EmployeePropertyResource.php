<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EmployeePropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'item_name'     => $this->item_name,
            'description'   => $this->description,
            'quantity'      => (int) $this->quantity,
            'replacement_unit_cost' => (string) $this->replacement_unit_cost,
            'replacement_total' => number_format((int) $this->quantity * (float) $this->replacement_unit_cost, 2, '.', ''),
            'date_issued'   => optional($this->date_issued)->toDateString(),
            'date_returned' => optional($this->date_returned)->toDateString(),
            'status'        => $this->status,
            'status_label'  => Str::headline((string) $this->status),
            'deleted_at'    => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
