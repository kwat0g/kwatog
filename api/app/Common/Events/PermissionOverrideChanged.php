<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Enums\PermissionOverrideType;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PermissionOverrideChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $permissionSlug,
        public ?PermissionOverrideType $oldType,
        public ?PermissionOverrideType $newType,
        public string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'permission.override.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'permission_slug' => $this->permissionSlug,
            'old_type' => $this->oldType?->value,
            'new_type' => $this->newType?->value,
            'reason' => $this->reason,
        ];
    }
}