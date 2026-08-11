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

/**
 * Single entry point for in-app + email notifications.
 *
 * Three properties this class guarantees, in order of importance:
 *
 * 1. **Nothing escapes an uncommitted transaction.** Almost every caller runs
 *    inside `DB::transaction()` (NcrService, DeliveryService, the approval
 *    listeners…). Broadcasting or emailing inline meant a rollback still left
 *    the recipient with a toast and an inbox message for an event that never
 *    happened. The row insert stays inline (it rolls back with its caller);
 *    the two *irreversible* side effects are deferred through
 *    `DB::afterCommit()`, which runs them immediately when no transaction is
 *    open, so single-statement callers behave exactly as before.
 * 2. **Cost is per-send, not per-recipient.** Preferences load in one query
 *    for the whole audience and rows insert in one statement. A 60-person
 *    role broadcast used to cost 180 queries.
 * 3. **The envelope is always well-formed.** `title` and `message` are
 *    coerced and length-clamped so a malformed caller can never write a row
 *    the bell cannot render or an unbounded blob into the JSON column.
 */
class NotificationService
{
    /** Titles render in a 320px dropdown; anything longer is noise. */
    private const MAX_TITLE = 255;

    /** Message bodies are previewed truncated everywhere they appear. */
    private const MAX_MESSAGE = 2000;

    /** Postgres handles far more, but a single INSERT has a bind-param ceiling. */
    private const INSERT_CHUNK = 500;

    /**
     * Send an in-app notification with a standardized data envelope.
     *
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @param  array{title?: string, message?: string, link_to?: string, entity_type?: string, entity_id?: string}  $data
     */
    public function send(
        User|Collection|array $recipients,
        string $type,
        array $data,
    ): void {
        $users = $this->normaliseRecipients($recipients);

        if ($users === []) {
            return;
        }

        $data = $this->normaliseEnvelope($type, $data);
        $prefs = $this->channelPreferences(array_keys($users), $type);

        $now = now();
        $encoded = json_encode($data);
        $rows = [];
        $events = [];
        $emails = [];

        foreach ($users as $userId => $user) {
            // in_app is opt-OUT: it fires unless an explicit row disables it.
            if (($prefs["{$userId}:in_app"] ?? true) === false) {
                continue;
            }

            $id = (string) Str::uuid();

            $rows[] = [
                'id' => $id,
                'type' => $type,
                'notifiable_type' => $user::class,
                'notifiable_id' => $userId,
                'data' => $encoded,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $events[] = new UserNotificationCreated($userId, [
                'id' => $id,
                'type' => $type,
                'data' => $data,
                'read_at' => null,
                'created_at' => $now->toISOString(),
            ]);

            // email is opt-IN: it requires an explicit enabled row plus a
            // usable address, so we never mail someone who never asked.
            if (($prefs["{$userId}:email"] ?? false) === true
                && is_string($user->email) && $user->email !== '') {
                $emails[] = [$user->email, $userId, $user->name ?? null];
            }
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        // Deferred: a rollback after this point must not leave a broadcast or
        // an email behind. Runs inline when no transaction is open.
        DB::afterCommit(function () use ($events, $emails, $type, $data): void {
            foreach ($events as $notificationEvent) {
                try {
                    // The notification row is the durable business result.
                    // Realtime delivery is an optimization: a broker/Reverb
                    // outage must not make the caller retry the notification
                    // insert and create duplicate inbox rows.
                    event($notificationEvent);
                } catch (\Throwable $e) {
                    Log::warning('Notification realtime broadcast failed; inbox row remains durable.', [
                        'user_id' => $notificationEvent->userId ?? null,
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($emails as [$address, $userId, $name]) {
                $this->queueEmail($address, $userId, $name, $type, $data);
            }
        });
    }

    /**
     * Legacy wrapper — delegates to Laravel's notification system.
     * Kept for backward compat with NcrService and MaintenanceWorkOrderService
     * that pass Notification objects. Will be removed once all callers migrate to send().
     */
    public function notify(User|Collection|array $recipients, $notification, string $type): void
    {
        $users = $this->normaliseRecipients($recipients);

        if ($users === []) {
            return;
        }

        $prefs = $this->channelPreferences(array_keys($users), $type);

        foreach ($users as $userId => $user) {
            if (($prefs["{$userId}:in_app"] ?? true) === false) {
                continue;
            }

            $user->notify($notification);
        }
    }

    /**
     * Collapse any accepted recipient shape into `[user_id => User]`.
     *
     * Keying by id deduplicates: role-based audiences are routinely unioned
     * (`$deptHeads->merge($hrOfficers)`) and a user holding both roles would
     * otherwise get the same alert twice.
     *
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @return array<int, User>
     */
    private function normaliseRecipients(User|Collection|array $recipients): array
    {
        $list = match (true) {
            $recipients instanceof User => [$recipients],
            $recipients instanceof Collection => $recipients->all(),
            default => $recipients,
        };

        $users = [];

        foreach ($list as $user) {
            if (! $user instanceof User || $user->id === null) {
                continue;
            }

            $users[(int) $user->id] = $user;
        }

        return $users;
    }

    /**
     * Load every relevant channel preference for the audience in one query.
     *
     * @param  array<int, int>  $userIds
     * @return array<string, bool> keyed `"{userId}:{channel}"`
     */
    private function channelPreferences(array $userIds, string $type): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('notification_preferences')
            ->whereIn('user_id', $userIds)
            ->where('notification_type', $type)
            ->whereIn('channel', ['in_app', 'email'])
            ->get(['user_id', 'channel', 'enabled'])
            ->reduce(function (array $carry, $row): array {
                $carry["{$row->user_id}:{$row->channel}"] = (bool) $row->enabled;

                return $carry;
            }, []);
    }

    /**
     * Guarantee a renderable envelope.
     *
     * A missing title used to produce a row the bell rendered as a blank line,
     * and nothing bounded `message`, so a caller interpolating an exception
     * trace could write megabytes into the JSON column.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseEnvelope(string $type, array $data): array
    {
        $title = $data['title'] ?? null;
        $data['title'] = is_string($title) && trim($title) !== ''
            ? Str::limit(trim($title), self::MAX_TITLE, '…')
            : Str::headline(str_replace(['.', '_'], ' ', $type));

        $message = $data['message'] ?? null;
        $data['message'] = is_string($message)
            ? Str::limit(trim($message), self::MAX_MESSAGE, '…')
            : '';

        if (isset($data['link_to']) && ! is_string($data['link_to'])) {
            unset($data['link_to']);
        }

        return $data;
    }

    /**
     * Dispatch a queued email. Failures never block the caller — mail is the
     * lossy channel; the in-app row is already durable by this point.
     *
     * @param  array<string, mixed>  $data
     */
    private function queueEmail(string $address, int $userId, ?string $name, string $type, array $data): void
    {
        try {
            Mail::to($address)->queue(new UserNotificationMail($type, $data, $name));
        } catch (\Throwable $e) {
            Log::warning('Notification email dispatch failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
