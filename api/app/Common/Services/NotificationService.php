<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\UserNotificationCreated;
use App\Common\Mail\UserNotificationMail;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

            // OGAMI-016 — email channel. In-app behaviour above is unchanged.
            // Email is opt-IN: it only fires when the user has an explicit,
            // enabled `email` preference row for this type AND a valid address.
            // This keeps the default (no-row) behaviour as in-app-only and
            // leaves existing notification tests green.
            $this->maybeEmail($user, $type, $data);
        }
    }

    /**
     * Dispatch a queued email when the recipient opted into the `email`
     * channel for this notification type. Failures never block the caller.
     *
     * @param array{title: string, message: string, link_to?: string, entity_type?: string, entity_id?: string} $data
     */
    private function maybeEmail(User $user, string $type, array $data): void
    {
        if (! $this->isEmailChannelEnabled($user, $type)) {
            return;
        }

        $email = $user->email ?? null;
        if (! is_string($email) || $email === '') {
            return;
        }

        try {
            Mail::to($email)->queue(new UserNotificationMail($type, $data, $user->name ?? null));
        } catch (\Throwable $e) {
            Log::warning('Notification email dispatch failed', [
                'user_id' => $user->id,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Email opt-in check. Unlike in_app (enabled by default), the email
     * channel requires an explicit enabled preference row so we never email
     * users who never asked for it.
     */
    private function isEmailChannelEnabled(User $user, string $type): bool
    {
        $pref = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('notification_type', $type)
            ->where('channel', 'email')
            ->first();

        return $pref !== null && (bool) $pref->enabled;
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
