<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'document_type'     => $this->document_type instanceof \BackedEnum ? $this->document_type->value : $this->document_type,
            'original_filename' => $this->original_filename,
            'file_size_bytes'   => $this->file_size_bytes !== null ? (int) $this->file_size_bytes : null,
            'mime_type'         => $this->mime_type,
            'notes'             => $this->notes,
            'url'               => $this->file_path ? "/api/v1/supply-chain/shipment-documents/{$this->hash_id}/download" : null,
            'uploaded_at'       => optional($this->uploaded_at)?->toISOString(),
            'uploader'          => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id'   => $this->uploader->hash_id,
                'name' => $this->uploader->name,
            ] : null),
        ];
    }
}
