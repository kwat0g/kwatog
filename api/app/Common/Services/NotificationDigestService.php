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
 * Batches each user's UNREAD in-app notifications into a single summary email.
 * Read state is left untouched (the digest is a reminder, not a "mark read"
 * action). Only users who opted into the `digest` channel and have at least
 * one unread notification receive mail. Idempotent: re-runs simply
 * re-summarise whatever is still unread.
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

    public function __construct(private readonly int $maxItemsPerUser = 20) {}

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
            // Keep the exact unread total separate from the display window.
            // The item query must never load an unbounded backlog merely to
            // calculate the count shown in the digest.
            $unreadCounts = $this->unreadCountsFor($userIds);

            if ($unreadCounts->isEmpty()) {
                continue;
            }

            $unreadByUser = $this->unreadFor($unreadCounts->keys()->map(
                static fn ($id): int => (int) $id,
            )->all());

            $users = User::query()
                ->whereIn('id', $unreadCounts->keys()->all())
                ->get()
                ->keyBy('id');

            foreach ($unreadCounts as $userId => $unreadCount) {
                $evaluated++;

                $user = $users->get((int) $userId);
                if (! $user || ! is_string($user->email) || $user->email === '') {
                    continue;
                }

                $rows = $unreadByUser->get((int) $userId, collect());
                $total = (int) $unreadCount;

                try {
                    Mail::to($user->email)->queue(new NotificationDigestMail(
                        $user->name ?? null,
                        $this->summarise($rows),
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
        return DB::table('notification_preferences')
            ->where('channel', 'digest')
            ->where('enabled', true)
            ->whereIn('notification_type', self::GLOBAL_OPT_IN_TYPES)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
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

    /**
     * Newest notifications for a bounded set of users, limited per user.
     *
     * A window function keeps this to one item query per subscriber batch,
     * while the outer filter prevents the PHP process from materialising an
     * entire unread backlog for every opted-in user.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Collection<int, object>>
     */
    private function unreadFor(array $userIds): Collection
    {
        if ($userIds === [] || $this->maxItemsPerUser === 0) {
            return collect();
        }

        $ranked = DB::table('notifications')
            ->select([
                'id',
                'notifiable_id',
                'type',
                'data',
                'created_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY notifiable_id ORDER BY created_at DESC, id DESC) as notification_rank',
            )
            ->whereNull('read_at')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds);

        return DB::query()
            ->fromSub($ranked, 'ranked_notifications')
            ->where('notification_rank', '<=', $this->maxItemsPerUser)
            ->orderBy('notifiable_id')
            ->orderBy('notification_rank')
            ->get(['notifiable_id', 'type', 'data', 'created_at'])
            ->groupBy('notifiable_id');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{title:string,message:string,link_to:string|null,type:string,created_at:string}>
     */
    private function summarise($rows): array
    {
        return $rows->take($this->maxItemsPerUser)->map(function ($r): array {
            $data = json_decode((string) $r->data, true);
            $data = is_array($data) ? $data : [];

            return [
                'title' => is_string($data['title'] ?? null) ? $data['title'] : 'Notification',
                'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
                'link_to' => is_string($data['link_to'] ?? null) ? $data['link_to'] : null,
                'type' => (string) $r->type,
                'created_at' => (string) $r->created_at,
            ];
        })->values()->all();
    }
}
