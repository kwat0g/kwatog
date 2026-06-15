<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'document_id'     => $this->document?->hash_id,
            'revision_number' => (int) $this->revision_number,
            'effective_date'  => $this->effective_date?->toDateString(),
            'change_reason'   => $this->change_reason,
            'file_name'       => $this->file_name,
            'file_size'       => $this->file_size !== null ? (int) $this->file_size : null,
            'mime_type'       => $this->mime_type,
            'published_at'    => $this->published_at?->toISOString(),
            'is_current'      => (bool) $this->is_current,
            'publisher'       => $this->whenLoaded('publisher', fn () => $this->publisher ? [
                'id'   => $this->publisher->hash_id,
                'name' => $this->publisher->name,
            ] : null),
        ];
    }
}
