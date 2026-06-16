<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Mail\NotificationDigestMail;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * OGAMI-016 — unread-notification digest.
 *
 * Batches each user's UNREAD in-app notifications into a single summary email.
 * Read state is left untouched (the digest is a reminder, not a "mark read"
 * action). Only users who opted into the `digest` channel and have at least
 * one unread notification receive mail. Idempotent in the sense that re-runs
 * simply re-summarise whatever is still unread.
 */
class NotificationDigestService
{
    public function __construct(private readonly int $maxItemsPerUser = 20) {}

    /**
     * @return array{users_evaluated:int, emails_sent:int, notifications_summarised:int}
     */
    public function run(): array
    {
        // Group unread notifications by recipient (User morph only).
        $unreadByUser = DB::table('notifications')
            ->whereNull('read_at')
            ->where('notifiable_type', User::class)
            ->orderBy('notifiable_id')
            ->orderByDesc('created_at')
            ->get(['notifiable_id', 'type', 'data', 'created_at'])
            ->groupBy('notifiable_id');

        $evaluated   = 0;
        $emailsSent  = 0;
        $summarised  = 0;

        foreach ($unreadByUser as $userId => $rows) {
            $evaluated++;

            if (! $this->digestEnabled((int) $userId)) {
                continue;
            }

            $user = User::query()->find($userId);
            if (! $user || ! is_string($user->email) || $user->email === '') {
                continue;
            }

            $items = $rows->take($this->maxItemsPerUser)->map(function ($r) {
                $data = json_decode((string) $r->data, true) ?: [];
                return [
                    'title'      => $data['title'] ?? 'Notification',
                    'message'    => $data['message'] ?? '',
                    'link_to'    => $data['link_to'] ?? null,
                    'type'       => $r->type,
                    'created_at' => (string) $r->created_at,
                ];
            })->values()->all();

            try {
                Mail::to($user->email)->queue(new NotificationDigestMail(
                    $user->name ?? null,
                    $items,
                    $rows->count(),
                ));
                $emailsSent++;
                $summarised += $rows->count();
            } catch (\Throwable $e) {
                Log::warning('Notification digest dispatch failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return [
            'users_evaluated'          => $evaluated,
            'emails_sent'              => $emailsSent,
            'notifications_summarised' => $summarised,
        ];
    }

    /**
     * Digest is opt-in via an explicit enabled `digest` channel preference.
     * We treat the special type '*' as a global opt-in for all digests.
     */
    private function digestEnabled(int $userId): bool
    {
        $pref = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('channel', 'digest')
            ->whereIn('notification_type', ['*', 'all', 'digest'])
            ->first();

        return $pref !== null && (bool) $pref->enabled;
    }
}
