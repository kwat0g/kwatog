<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class FxRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'currency_code'      => $this->currency_code,
            'rate_date'          => optional($this->rate_date)->toDateString(),
            'rate_to_functional' => (string) $this->rate_to_functional,
            'source'             => $this->source,
            'source_label'       => Str::headline((string) $this->source),
            'created_at'         => optional($this->created_at)->toIso8601String(),
        ];
    }
}
