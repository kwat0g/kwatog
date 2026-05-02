<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'item_name'     => $this->item_name,
            'description'   => $this->description,
            'quantity'      => (int) $this->quantity,
            'date_issued'   => optional($this->date_issued)->toDateString(),
            'date_returned' => optional($this->date_returned)->toDateString(),
            'status'        => $this->status,
        ];
    }
}
