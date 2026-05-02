<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->hash_id,
            'name'                         => $this->name,
            'code'                         => $this->code,
            'default_balance'              => (string) $this->default_balance,
            'is_paid'                      => (bool) $this->is_paid,
            'requires_document'            => (bool) $this->requires_document,
            'is_convertible_on_separation' => (bool) $this->is_convertible_on_separation,
            'is_convertible_year_end'      => (bool) $this->is_convertible_year_end,
            'conversion_rate'              => (string) $this->conversion_rate,
            'is_active'                    => (bool) $this->is_active,
            'created_at'                   => optional($this->created_at)->toIso8601String(),
            'updated_at'                   => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
