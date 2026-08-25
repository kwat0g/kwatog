<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPortalUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'name'                => $this->name,
            'email'               => $this->email,
            'customer_id'         => app('hashids')->encode((int) $this->customer_id),
            'customer_name'       => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'must_change_password' => (bool) $this->must_change_password,
            'customer'            => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'last_login_at'       => optional($this->last_login_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
