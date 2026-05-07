<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LoginHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->hash_id,
            'status'          => $this->status,
            'reason'          => $this->reason,
            'email_attempted' => $this->email_attempted,
            'ip_address'      => $this->ip_address,
            'user_agent'      => $this->user_agent,
            'created_at'      => optional($this->created_at)->toIso8601String(),
        ];
    }
}
