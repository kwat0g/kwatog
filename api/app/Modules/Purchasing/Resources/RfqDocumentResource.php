<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'document_type' => $this->document_type,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name] : null),
        ];
    }
}
