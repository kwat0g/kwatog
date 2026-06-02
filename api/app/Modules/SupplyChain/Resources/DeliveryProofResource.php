<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryProofResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deliveryHash = $this->whenLoaded('delivery', fn () => $this->delivery->hash_id);

        return [
            'id'          => $this->hash_id,
            'proof_type'  => $this->proof_type,
            'file_name'   => $this->file_name,
            'file_size'   => $this->file_size,
            'mime_type'   => $this->mime_type,
            'is_image'    => $this->mime_type ? str_starts_with((string) $this->mime_type, 'image/') : false,
            'notes'       => $this->notes,
            'view_url'    => $deliveryHash
                ? "/api/v1/supply-chain/deliveries/{$deliveryHash}/proofs/{$this->hash_id}/view"
                : null,
            'uploader'    => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id'   => $this->uploader->hash_id,
                'name' => $this->uploader->name,
            ] : null),
            'uploaded_at' => optional($this->created_at)?->toISOString(),
        ];
    }
}
