<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqAddendumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->hash_id, 'sequence' => (int) $this->sequence, 'title' => $this->title, 'body' => $this->body, 'material_change' => (bool) $this->material_change, 'published_at' => optional($this->published_at)->toIso8601String(), 'publisher' => $this->whenLoaded('publisher', fn () => ['id' => $this->publisher->hash_id, 'name' => $this->publisher->name])];
    }
}
