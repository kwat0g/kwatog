<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Mail\NotificationDigestMail;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * OGAMI-016 — unread-notification digest.
 *
 * Counts each user's unread in-app notifications for a summary email. It never
 * reads notification payloads. Read state is left untouched; only active users
 * who opted into the `digest` channel and have at least one unread item receive
 * mail. Re-runs report whatever remains unread.
 *
 * **Opt-in is resolved first, and that ordering is the whole design.** The
 * original implementation selected every unread row for every user in the
 * system into one collection, grouped it in PHP, and only then asked whether
 * each user wanted a digest at all. Digest opt-in is rare and unread
 * notifications accumulate without bound (nothing marks them read, and prune
 * only touches *read* rows older than 90 days), so the command's memory
 * footprint was set by total unread volume rather than by how many people
 * subscribed — a scheduled 07:05 job that gets heavier every day it runs and
 * ends in an OOM. Now the opt-in list bounds everything: no subscribers means
 * one cheap query and no notification rows loaded at all.
 */
class NotificationDigestService
{
    /** Type keys accepted as a global "digest me" opt-in row. */
    private const GLOBAL_OPT_IN_TYPES = ['*', 'all', 'digest'];

    /** Users processed per batch, bounding peak memory regardless of subscriber count. */
    private const USER_CHUNK = 100;

    /**
     * @return array{users_evaluated:int, emails_sent:int, notifications_summarised:int, failures:int}
     */
    public function run(): array
    {
        $evaluated = 0;
        $emailsSent = 0;
        $summarised = 0;
        $failures = 0;

        foreach (array_chunk($this->subscriberIds(), self::USER_CHUNK) as $userIds) {
            // Count without loading titles or message bodies into the mail job.
            $unreadCounts = $this->unreadCountsFor($userIds);

            if ($unreadCounts->isEmpty()) {
                continue;
            }

            $users = User::query()
                ->whereIn('id', $unreadCounts->keys()->all())
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            foreach ($unreadCounts as $userId => $unreadCount) {
                $evaluated++;

                $user = $users->get((int) $userId);
                if (! $user || ! is_string($user->email) || filter_var(trim($user->email), FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $total = (int) $unreadCount;

                try {
                    Mail::to(trim($user->email))->queue(new NotificationDigestMail(
                        $user->name ?? null,
                        $total,
                        (int) $user->id,
                    ));
                    $emailsSent++;
                    $summarised += $total;
                } catch (\Throwable $e) {
                    $failures++;
                    Log::warning('Notification digest dispatch failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'users_evaluated' => $evaluated,
            'emails_sent' => $emailsSent,
            'notifications_summarised' => $summarised,
            'failures' => $failures,
        ];
    }

    /**
     * Users who explicitly opted into the digest channel.
     *
     * @return array<int, int>
     */
    private function subscriberIds(): array
    {
        $ids = DB::table('notification_preferences')
            ->where('channel', 'digest')
            ->where('enabled', true)
            ->whereIn('notification_type', self::GLOBAL_OPT_IN_TYPES)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get(['id', 'email'])
            ->filter(static fn (User $user): bool => is_string($user->email)
                && filter_var(trim($user->email), FILTER_VALIDATE_EMAIL) !== false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Exact unread totals for a bounded set of users.
     *
     * This query intentionally does not select notification payloads. It is
     * the count source for the digest total while unreadFor() fetches only the
     * newest display window.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, int>
     */
    private function unreadCountsFor(array $userIds): Collection
    {
        return DB::table('notifications')
            ->select('notifiable_id')
            ->selectRaw('COUNT(*) as unread_count')
            ->whereNull('read_at')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->groupBy('notifiable_id')
            ->pluck('unread_count', 'notifiable_id')
            ->map(static fn ($count): int => (int) $count);
    }

}
