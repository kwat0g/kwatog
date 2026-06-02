<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPortalUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'vendor'       => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'last_login_at' => optional($this->last_login_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
        ];
    }
}
