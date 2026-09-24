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
            'is_active'    => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'status'       => $this->portalStatus(),
            'deleted_at'   => optional($this->deleted_at)->toIso8601String(),
            'vendor_id'    => app('hashids')->encode((int) $this->vendor_id),
            'vendor_name'  => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'vendor'       => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'last_login_at' => optional($this->last_login_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function portalStatus(): string
    {
        if ($this->trashed() || ! $this->is_active
            || ($this->relationLoaded('vendor') && (! $this->vendor || ! $this->vendor->is_active))) return 'inactive';
        if ($this->isLocked()) return 'locked';
        if ($this->must_change_password) return 'pending';
        return 'active';
    }
}
