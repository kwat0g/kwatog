<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id, 'status' => $this->status?->value ?? (string) $this->status,
            'invited_at' => optional($this->invited_at)->toIso8601String(), 'viewed_at' => optional($this->viewed_at)->toIso8601String(),
            'exception_reason' => $this->exception_reason,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name]),
        ];
    }
}
