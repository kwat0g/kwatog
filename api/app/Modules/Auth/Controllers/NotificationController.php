<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\NotificationPreference;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 — Task 77. Notifications + per-user channel preferences.
 *
 * Backed by the default Laravel `notifications` table (uuid PK, polymorphic
 * notifiable). All endpoints scope strictly to the authenticated user.
 */
class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id);

        if ($request->boolean('unread_only')) {
            $q->whereNull('read_at');
        }
        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        $perPage = min((int) ($request->integer('per_page') ?: 25), 100);
        $rows = $q->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($rows->items())->map(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'data'       => is_string($n->data) ? json_decode($n->data, true) : $n->data,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at,
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
                'unread_count' => DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $user->id)
                    ->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $updated = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->update(['read_at' => now()]);
        if (! $updated) abort(404);
        return response()->json(['data' => ['id' => $id, 'read_at' => now()->toISOString()]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['data' => ['marked_read' => $count]]);
    }

    public function preferencesIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = NotificationPreference::query()->where('user_id', $user->id)->get();
        return response()->json([
            'data' => $rows->map(fn (NotificationPreference $p) => [
                'id'                => $p->hash_id,
                'notification_type' => $p->notification_type,
                'channel'           => $p->channel,
                'enabled'           => (bool) $p->enabled,
            ])->all(),
        ]);
    }

    public function preferencesUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences'                       => ['required', 'array'],
            'preferences.*.notification_type'   => ['required', 'string', 'max:100'],
            'preferences.*.channel'             => ['required', 'in:in_app,email'],
            'preferences.*.enabled'             => ['required', 'boolean'],
        ]);
        $user = $request->user();
        foreach ($data['preferences'] as $row) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id'           => $user->id,
                    'notification_type' => $row['notification_type'],
                    'channel'           => $row['channel'],
                ],
                ['enabled' => (bool) $row['enabled']],
            );
        }
        return $this->preferencesIndex($request);
    }
}
