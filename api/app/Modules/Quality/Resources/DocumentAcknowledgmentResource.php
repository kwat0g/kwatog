<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentAcknowledgmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'acknowledged_at' => $this->acknowledged_at?->toISOString(),
            'acknowledged'    => $this->acknowledged_at !== null,
            'revision'        => $this->whenLoaded('revision', fn () => $this->revision ? [
                'id'              => $this->revision->hash_id,
                'revision_number' => (int) $this->revision->revision_number,
                'effective_date'  => $this->revision->effective_date?->toDateString(),
                'file_name'       => $this->revision->file_name,
                'document'        => $this->revision->document ? [
                    'id'       => $this->revision->document->hash_id,
                    'code'     => $this->revision->document->code,
                    'title'    => $this->revision->document->title,
                    'category' => $this->revision->document->category,
                ] : null,
            ] : null),
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
