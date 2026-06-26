<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
