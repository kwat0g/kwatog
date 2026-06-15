<?php

declare(strict_types=1);

namespace App\Modules\Edge\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EdgeScanResultResource extends JsonResource
{
    /**
     * Wraps the resolver's already-shaped array. Keeps the response under
     * the standard `{ data: ... }` envelope.
     */
    public function toArray($request): array
    {
        return $this->resource;
    }
}
