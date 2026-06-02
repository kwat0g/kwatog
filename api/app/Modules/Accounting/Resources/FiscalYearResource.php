<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalYearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->hash_id,
            'year'       => $this->year,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date'   => $this->end_date?->format('Y-m-d'),
            'status'     => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
