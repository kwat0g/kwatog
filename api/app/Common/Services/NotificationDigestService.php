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
            $unreadByUser = $this->unreadFor($userIds);

            if ($unreadByUser->isEmpty()) {
                continue;
            }

            $users = User::query()
                ->whereIn('id', $unreadByUser->keys()->all())
                ->get()
                ->keyBy('id');

            foreach ($unreadByUser as $userId => $rows) {
                $evaluated++;

                $user = $users->get((int) $userId);
                if (! $user || ! is_string($user->email) || $user->email === '') {
                    continue;
                }

                $total = $rows->count();

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
     * Unread notifications for a bounded set of users, newest first.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Collection<int, object>>
     */
    private function unreadFor(array $userIds)
    {
        return DB::table('notifications')
            ->whereNull('read_at')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->orderBy('notifiable_id')
            ->orderByDesc('created_at')
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
