<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\PortalNotificationChanged;
use App\Common\Events\UserNotificationCreated;
use App\Common\Mail\UserNotificationMail;
use App\Common\Support\NotificationSubjectAccessRegistry;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

/**
 * Central entry point for structured in-app notices and preference-controlled email copies.
 * Mail-only authentication, report, and critical-alert flows use Laravel notifications.
 *
 * Three properties this class guarantees, in order of importance:
 *
 * 1. **Nothing escapes an uncommitted transaction.** Notification rows and
 *    durable realtime outbox entries commit together. The outbox publishes
 *    after commit, and preference-controlled email is also deferred until
 *    commit so a rollback cannot leave an inbox toast or email behind.
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

    /** New custom notification rows use this additive payload schema. */
    private const ENVELOPE_VERSION = 1;

    /** Postgres handles far more, but a single INSERT has a bind-param ceiling. */
    private const INSERT_CHUNK = 500;

    /** @return Collection<int, User> Active users who currently hold a permission. */
    public function usersWithPermission(string $permission): Collection
    {
        $permissionId = DB::table('permissions')->where('slug', $permission)->value('id');
        if ($permissionId === null) {
            return collect();
        }

        $roleIds = DB::table('role_permissions')->where('permission_id', $permissionId)->pluck('role_id');
        $overrideIds = DB::table('user_permission_overrides')
            ->where('permission_id', $permissionId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($roleIds, $overrideIds): void {
                $query->whereHas('role', fn ($role) => $role->where('slug', 'system_admin'));
                if ($roleIds->isNotEmpty()) {
                    $query->orWhereIn('role_id', $roleIds);
                }
                if ($overrideIds->isNotEmpty()) {
                    $query->orWhereIn('id', $overrideIds);
                }
            })
            ->with('role')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission($permission))
            ->values();
    }

    /** Store portal notices without internal-user preferences, email, or broadcast channels. */
    public function sendPortalInApp(iterable $recipients, string $type, array $data, ?string $dedupeKey = null): void
    {
        $data = $this->normaliseEnvelope($type, $data);
        $users = [];
        foreach ($recipients as $user) {
            if ((! $user instanceof CustomerPortalUser && ! $user instanceof SupplierPortalUser)
                || $user->id === null || ! $user->is_active || $user->trashed()) {
                continue;
            }
            $users[$user::class.':'.$user->id] = $user;
        }
        $now = now();
        $rows = [];
        $eventsById = [];
        foreach ($users as $user) {
            $id = (string) Str::uuid();
            $rows[] = [
                'id' => $id, 'type' => $type,
                'notifiable_type' => $user::class, 'notifiable_id' => $user->id,
                'dedupe_key' => $dedupeKey, 'data' => json_encode($data),
                'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
            $eventsById[$id] = new PortalNotificationChanged(
                $user instanceof CustomerPortalUser ? 'customer' : 'supplier',
                (string) $user->hash_id,
                'created',
                $id,
                $type,
            );
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($rows, $dedupeKey, $eventsById): void {
            $insertedIds = [];
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                if ($dedupeKey === null) {
                    DB::table('notifications')->insert($chunk);
                    foreach ($chunk as $row) {
                        $insertedIds[] = $row['id'];
                    }
                } else {
                    foreach ($chunk as $row) {
                        if (DB::table('notifications')->insertOrIgnore([$row]) === 1) {
                            $insertedIds[] = $row['id'];
                        }
                    }
                }
            }

            $events = array_intersect_key($eventsById, array_fill_keys($insertedIds, true));
            foreach ($events as $notificationEvent) {
                app(OutboxService::class)->record($notificationEvent);
            }
        });
    }

    public function stagePortalInboxChange(
        CustomerPortalUser|SupplierPortalUser $user,
        string $action,
        ?string $notificationId = null,
    ): void {
        app(OutboxService::class)->record(new PortalNotificationChanged(
            $user instanceof CustomerPortalUser ? 'customer' : 'supplier',
            (string) $user->hash_id,
            $action,
            $notificationId,
        ));
    }

    /**
     * Send an in-app notification with a standardized data envelope.
     *
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @param  array{title?: string, message?: string, link_to?: string, entity_type?: string, entity_id?: string}  $data
     * @param  array<int, string>  $recipientReasons  Per-user explanation codes, keyed by internal user ID.
     */
    public function send(
        User|Collection|array $recipients,
        string $type,
        array $data,
        ?string $dedupeKey = null,
        array $recipientReasons = [],
    ): void {
        $this->dispatch($recipients, $type, $data, true, $dedupeKey, $recipientReasons);
    }

    /**
     * Persist and broadcast an in-app notification without queueing email.
     *
     * This is deliberately a separate entry point for delivery fallbacks.
     * Calling send() from an email failure handler would simply try the same
     * broken email channel again for users who opted into email.
     *
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @param  array{title?: string, message?: string, link_to?: string, entity_type?: string, entity_id?: string}  $data
     * @param  array<int, string>  $recipientReasons  Per-user explanation codes, keyed by internal user ID.
     */
    public function sendInApp(
        User|Collection|array $recipients,
        string $type,
        array $data,
        ?string $dedupeKey = null,
        array $recipientReasons = [],
    ): void {
        $this->dispatch($recipients, $type, $data, false, $dedupeKey, $recipientReasons);
    }

    /**
     * Send only to recipients who can open a registered subject.
     * Unknown subject types fail closed until their module access rule is registered.
     *
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $recipientReasons
     */
    public function sendForSubject(
        Model $subject,
        User|Collection|array $recipients,
        string $type,
        array $data,
        ?string $dedupeKey = null,
        array $recipientReasons = [],
        bool $emailEnabled = true,
    ): void {
        $class = $subject->getMorphClass();
        if (! $subject->exists || $subject->getKey() === null
            || ! $subject->newQuery()->whereKey($subject->getKey())->exists()) {
            Log::warning('Notification delivery skipped: subject is unavailable.', [
                'type' => $type,
                'subject_type' => $class,
                'subject_id' => $subject->getKey(),
            ]);

            return;
        }
        if (! NotificationSubjectAccessRegistry::supports($class)) {
            Log::warning('Notification delivery skipped: subject has no registered access rule.', [
                'type' => $type,
                'subject_type' => $class,
                'subject_id' => $subject->getKey(),
            ]);

            return;
        }

        $eligible = [];
        $deniedUserIds = [];
        $linkTo = is_string($data['link_to'] ?? null) ? $data['link_to'] : null;
        foreach ($this->normaliseRecipients($recipients) as $userId => $user) {
            if (NotificationSubjectAccessRegistry::userCanViewRecord($user, $subject, $linkTo)) {
                $eligible[$userId] = $user;
            } else {
                $deniedUserIds[] = $userId;
            }
        }

        if ($deniedUserIds !== []) {
            Log::warning('Notification recipients lack subject access; delivery suppressed.', [
                'type' => $type,
                'subject_type' => $class,
                'subject_id' => $subject->getKey(),
                'user_ids' => $deniedUserIds,
            ]);
        }

        $data['subject_type'] = $class;
        $data['subject_id'] = (string) $subject->getKey();
        $this->dispatch($eligible, $type, $data, $emailEnabled, $dedupeKey, $recipientReasons, $subject);
    }

    /** Deliver an existing Laravel notification through the central dispatcher. */
    public function sendLaravelNotification(User|Collection|array $recipients, LaravelNotification $notification): void
    {
        $users = $this->normaliseRecipients($recipients);
        if ($users === []) {
            Log::warning('Laravel notification delivery skipped: no eligible recipients.', [
                'notification_class' => $notification::class,
            ]);

            return;
        }

        NotificationFacade::send($users, $notification);
    }

    /**
     * Deliver one DSS recommendation notice under the per-user daily cap.
     * The caller must pass a stable occurrence/change identity; existing
     * notification uniqueness makes retries safe across workers.
     */
    public function sendDssRecommendationInApp(User $recipient, array $data, string $eventIdentity): bool
    {
        if ($recipient->id === null || trim($eventIdentity) === '') {
            return false;
        }

        $cap = (int) config('dss.notification_daily_cap', 0);
        $dedupeKey = 'dss:'.hash('sha256', $eventIdentity);

        return DB::transaction(function () use ($recipient, $data, $dedupeKey, $cap): bool {
            $lockedRecipient = User::query()->whereKey($recipient->id)->lockForUpdate()->first();
            $notifications = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $recipient->id)
                ->where('type', 'dss.recommendation');

            if ((clone $notifications)->where('dedupe_key', $dedupeKey)->exists()) {
                return true;
            }
            if ($lockedRecipient === null || ! $lockedRecipient->is_active) {
                Log::warning('DSS recommendation delivery skipped: no active recipient.', [
                    'type' => 'dss.recommendation',
                ]);

                return false;
            }
            if ($cap < 1) {
                return false;
            }

            $localNow = now(config('app.timezone'));
            $todayCount = (clone $notifications)
                ->whereBetween('created_at', [$localNow->copy()->startOfDay(), $localNow->copy()->endOfDay()])
                ->count();
            if ($todayCount >= $cap) {
                return false;
            }

            $this->sendInApp($lockedRecipient, 'dss.recommendation', $data, $dedupeKey);

            return DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $lockedRecipient->id)
                ->where('type', 'dss.recommendation')
                ->where('dedupe_key', $dedupeKey)
                ->exists();
        });
    }

    /**
     * @param  User|Collection<int, User>|array<int, User>  $recipients
     * @param  array<string, mixed>  $data
     */
    private function dispatch(
        User|Collection|array $recipients,
        string $type,
        array $data,
        bool $emailEnabled,
        ?string $dedupeKey,
        array $recipientReasons = [],
        ?Model $failureSubject = null,
    ): void {
        $users = $this->normaliseRecipients($recipients);

        if ($users === []) {
            Log::warning('Notification delivery skipped: no eligible recipients.', [
                'type' => $type,
            ]);

            return;
        }

        $data = $this->normaliseEnvelope($type, $data);
        $prefs = $this->channelPreferences(array_keys($users), $type);

        $now = now();
        $rows = [];
        $events = [];
        $emails = [];

        foreach ($users as $userId => $user) {
            $recipientData = $data;
            $reason = $recipientReasons[$userId] ?? null;
            if (is_string($reason) && $reason !== '') {
                $recipientData['recipient_reason'] = $reason;
            }

            // in_app is opt-OUT: it fires unless an explicit row disables it.
            if (($prefs["{$userId}:in_app"] ?? true) !== false) {
                $id = (string) Str::uuid();

                $rows[] = [
                    'id' => $id,
                    'type' => $type,
                    'notifiable_type' => $user::class,
                    'notifiable_id' => $userId,
                    'dedupe_key' => $dedupeKey,
                    'data' => json_encode($recipientData),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $events[] = new UserNotificationCreated($userId, $id, $type);
            }

            // email is opt-IN: it requires an explicit enabled row plus a
            // usable address, so we never mail someone who never asked.
            if ($emailEnabled
                && ($prefs["{$userId}:email"] ?? false) === true
                && $user->is_active
                && is_string($user->email)
                && filter_var(trim($user->email), FILTER_VALIDATE_EMAIL) !== false) {
                $address = trim($user->email);
                $key = strtolower($address);
                $emails[$key] ??= ['address' => $address, 'userIds' => [], 'names' => []];
                $emails[$key]['userIds'][] = $userId;
                $emails[$key]['names'][] = $user->name;
            }
        }

        if ($rows === [] && $emails === []) {
            return;
        }

        DB::transaction(function () use ($rows, $events, $dedupeKey): void {
            $insertedIds = [];
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                if ($dedupeKey === null) {
                    DB::table('notifications')->insert($chunk);
                    foreach ($chunk as $row) {
                        $insertedIds[$row['id']] = true;
                    }

                    continue;
                }

                // The unique key is the race-proof part. Insert one row at a
                // time only for the opt-in path so a replay cannot broadcast
                // an event for a notification row it did not create.
                foreach ($chunk as $row) {
                    if (DB::table('notifications')->insertOrIgnore([$row]) === 1) {
                        $insertedIds[$row['id']] = true;
                    }
                }
            }

            $eventsToPublish = $dedupeKey === null
                ? $events
                : array_values(array_filter(
                    $events,
                    static fn (UserNotificationCreated $event): bool => isset($insertedIds[$event->notificationId]),
                ));

            app(OutboxService::class)->recordManyAndDispatchInlineAfterCommit($eventsToPublish);
        });

        // Realtime signals are staged with their rows above; only email needs
        // a separate callback after commit.
        DB::afterCommit(function () use ($emails, $type, $data, $dedupeKey, $failureSubject): void {
            foreach ($emails as $email) {
                $recipientUserIds = array_values(array_unique($email['userIds']));
                $name = count($recipientUserIds) === 1 ? $email['names'][0] : null;
                $deliveryKey = $dedupeKey === null
                    ? null
                    : hash('sha256', $type.'|'.$dedupeKey.'|'.strtolower($email['address']));
                $this->queueEmail($email['address'], $recipientUserIds, $name, $type, $data, $deliveryKey, $failureSubject);
            }
        });
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
            if (! $user instanceof User || $user->id === null || ! $user->is_active || $user->trashed()) {
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

        $data['schema_version'] = self::ENVELOPE_VERSION;
        $data['event_type'] = $type;

        $subjectType = $data['subject_type'] ?? $data['entity_type'] ?? null;
        $subjectId = $data['subject_id'] ?? $data['entity_id'] ?? null;
        if (is_string($subjectType) && trim($subjectType) !== ''
            && (is_int($subjectId) || is_string($subjectId)) && trim((string) $subjectId) !== '') {
            $data['subject_type'] = trim($subjectType);
            $data['subject_id'] = trim((string) $subjectId);
        } else {
            unset($data['subject_type'], $data['subject_id']);
        }

        if (is_string($data['actor_id'] ?? null)) {
            $data['actor_id'] = trim($data['actor_id']);
            if ($data['actor_id'] === '') {
                unset($data['actor_id']);
            }
        } else {
            unset($data['actor_id']);
        }

        return $data;
    }

    /**
     * Dispatch a queued email. Failures never block the caller — mail is the
     * lossy channel; the in-app row is already durable by this point.
     *
     * @param  array<string, mixed>  $data
     */
    private function queueEmail(
        string $address,
        array $recipientUserIds,
        ?string $name,
        string $type,
        array $data,
        ?string $deliveryKey,
        ?Model $failureSubject = null,
    ): void {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Notification email skipped: invalid recipient address', [
                'user_id' => $recipientUserIds[0] ?? null,
                'type' => $type,
            ]);

            return;
        }

        try {
            Mail::to($address)->queue(new UserNotificationMail(
                $type,
                $data,
                $name,
                $recipientUserIds[0] ?? null,
                $recipientUserIds,
                deliveryKey: $deliveryKey,
                failureSubject: $failureSubject,
            ));
        } catch (\Throwable $e) {
            Log::warning('Notification email dispatch failed', [
                'user_ids' => $recipientUserIds,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
