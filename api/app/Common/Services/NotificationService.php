<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\UserNotificationCreated;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send an in-app notification with a standardized data envelope.
     *
     * @param User|Collection<int, User>|array<int, User> $recipients
     * @param array{title: string, message: string, link_to?: string, entity_type?: string, entity_id?: string} $data
     */
    public function send(
        User|Collection|array $recipients,
        string $type,
        array $data,
    ): void {
        $list = match (true) {
            $recipients instanceof User       => collect([$recipients]),
            $recipients instanceof Collection => $recipients,
            default                           => collect($recipients),
        };

        foreach ($list as $user) {
            if (! $this->isChannelEnabled($user, $type, 'in_app')) {
                continue;
            }

            $id = (string) Str::uuid();
            $now = now();

            DB::table('notifications')->insert([
                'id'              => $id,
                'type'            => $type,
                'notifiable_type' => $user::class,
                'notifiable_id'   => $user->id,
                'data'            => json_encode($data),
                'read_at'         => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            event(new UserNotificationCreated($user->id, [
                'id'         => $id,
                'type'       => $type,
                'data'       => $data,
                'read_at'    => null,
                'created_at' => $now->toISOString(),
            ]));
        }
    }

    /**
     * Legacy wrapper — delegates to Laravel's notification system.
     * Kept for backward compat with NcrService and MaintenanceWorkOrderService
     * that pass Notification objects. Will be removed once all callers migrate to send().
     */
    public function notify(User|Collection|array $recipients, $notification, string $type): void
    {
        $list = match (true) {
            $recipients instanceof User       => collect([$recipients]),
            $recipients instanceof Collection => $recipients,
            default                           => collect($recipients),
        };

        foreach ($list as $user) {
            if (! $this->isChannelEnabled($user, $type, 'in_app')) {
                continue;
            }
            $user->notify($notification);
        }
    }

    private function isChannelEnabled(User $user, string $type, string $channel): bool
    {
        $pref = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('notification_type', $type)
            ->where('channel', $channel)
            ->first();

        // Default: enabled if no preference row exists
        if (! $pref) {
            return true;
        }

        return (bool) $pref->enabled;
    }
}
