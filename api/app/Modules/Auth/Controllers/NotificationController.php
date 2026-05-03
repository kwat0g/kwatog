<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\NotificationPreference;
use App\Modules\Auth\Services\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 8 — Task 77. Notifications + per-user channel preferences.
 *
 * Backed by the default Laravel `notifications` table (uuid PK, polymorphic
 * notifiable). All endpoints scope strictly to the authenticated user.
 */
class NotificationController
{
    public function __construct(private readonly UserNotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->service->list($user, [
            'unread_only' => $request->boolean('unread_only'),
            'type'        => $request->filled('type') ? (string) $request->string('type') : null,
            'per_page'    => $request->integer('per_page') ?: 25,
        ]);

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
                'unread_count' => $this->service->unreadCount($user),
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => ['id' => $id, 'read_at' => $this->service->markRead($request->user(), $id)],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->service->markAllRead($request->user());
        return response()->json(['data' => ['marked_read' => $count]]);
    }

    public function preferencesIndex(Request $request): JsonResponse
    {
        $rows = $this->service->preferences($request->user());
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
        $this->service->updatePreferences($request->user(), $data['preferences']);
        return $this->preferencesIndex($request);
    }
}
