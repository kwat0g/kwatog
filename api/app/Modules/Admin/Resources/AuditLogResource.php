<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Support\AuditDiffBuilder;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->hash_id,
            'action'      => $this->action,
            'model_type'  => class_basename($this->model_type),
            'model_id'    => $this->model_id ? app('hashids')->encode((int) $this->model_id) : null,
            'actor_type'  => $this->actor_type,
            'source_command' => $this->source_command,
            'correlation_id' => $this->correlation_id,
            'reason'      => $this->reason,
            'old_values'  => $this->old_values,
            'new_values'  => $this->new_values,
            'diff'        => AuditDiffBuilder::build(
                (string) $this->model_type,
                (array) ($this->old_values ?? []),
                (array) ($this->new_values ?? []),
            ),
            'ip_address'  => $this->ip_address,
            'user_agent'  => $this->user_agent,
            'created_at'  => $this->created_at?->toISOString(),
            'user'        => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->hash_id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
                // ADV4 — role chip alongside the actor's name.
                'role'  => $this->user->role ? [
                    'name' => $this->user->role->name,
                    'slug' => $this->user->role->slug,
                ] : null,
            ]),
        ];
    }
}
