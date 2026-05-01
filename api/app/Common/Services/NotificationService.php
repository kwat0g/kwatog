<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wraps Laravel notifications with per-user channel preferences.
 * Falls back to all enabled channels if no preference row exists.
 */
class NotificationService
{
    /**
     * @param User|Collection<int, User>|array<int, User>  $recipients
     */
    public function notify(User|Collection|array $recipients, Notification $notification, string $type): void
    {
        $list = match (true) {
            $recipients instanceof User       => collect([$recipients]),
            $recipients instanceof Collection => $recipients,
            default                           => collect($recipients),
        };

        foreach ($list as $user) {
            $channels = $this->channelsFor($user, $type);
            if (! empty($channels)) {
                $user->notify($notification);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function channelsFor(User $user, string $type): array
    {
        $rows = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('notification_type', $type)
            ->get();

        if ($rows->isEmpty()) {
            return ['database']; // default in-app
        }

        return $rows->where('enabled', true)->pluck('channel')->all();
    }
}
