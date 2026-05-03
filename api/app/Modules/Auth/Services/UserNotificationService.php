<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\NotificationPreference;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserNotificationService
{
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id);

        if (! empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        }
        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, string $id): string
    {
        return DB::transaction(function () use ($user, $id): string {
            $readAt = now();
            $updated = DB::table('notifications')
                ->where('id', $id)
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->update(['read_at' => $readAt]);

            if (! $updated) abort(404);

            return $readAt->toISOString();
        });
    }

    public function markAllRead(User $user): int
    {
        return DB::transaction(fn (): int => (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]));
    }

    public function preferences(User $user)
    {
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->orderBy('notification_type')
            ->orderBy('channel')
            ->get();
    }

    /**
     * @param array<int, array{notification_type:string, channel:string, enabled:bool}> $preferences
     */
    public function updatePreferences(User $user, array $preferences): void
    {
        DB::transaction(function () use ($user, $preferences): void {
            foreach ($preferences as $row) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id'           => $user->id,
                        'notification_type' => $row['notification_type'],
                        'channel'           => $row['channel'],
                    ],
                    ['enabled' => (bool) $row['enabled']],
                );
            }
        });
    }
}
