<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->hash_id,
            'action'      => $this->action,
            'model_type'  => class_basename($this->model_type),
            'model_id'    => $this->model_id,
            'old_values'  => $this->old_values,
            'new_values'  => $this->new_values,
            'ip_address'  => $this->ip_address,
            'user_agent'  => $this->user_agent,
            'created_at'  => $this->created_at?->toISOString(),
            'user'        => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->hash_id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
