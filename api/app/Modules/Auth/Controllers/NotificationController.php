<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\NotificationPreference;
use App\Modules\Auth\Services\UserNotificationService;
use App\Common\Services\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 8 — Task 77. Notifications + per-user channel preferences.
 *
 * Backed by the default Laravel `notifications` table (uuid PK, polymorphic
 * notifiable). All endpoints scope strictly to the authenticated user.
 */
class NotificationController
{
    /** The pseudo-type carrying the global daily-digest opt-in. */
    private const DIGEST_TYPE = '*';

    public function __construct(private readonly UserNotificationService $service, private readonly NotificationCatalog $catalog) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['groups' => $this->catalog->groups()]]);
    }

    public function index(Request $request): JsonResponse
    {
        // `per_page` reached paginate() unvalidated. The service clamped only
        // the upper bound with min(), so `?per_page=-5` passed straight
        // through and Postgres rejected the negative LIMIT with a 500.
        $filters = $request->validate([
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type'        => ['sometimes', 'string', 'max:100'],
            'unread_only' => ['sometimes', 'boolean'],
            'page'        => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $rows = $this->service->list($user, [
            'unread_only' => $request->boolean('unread_only'),
            'type'        => $filters['type'] ?? null,
            'per_page'    => $filters['per_page'] ?? 25,
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
        // The catalog is the set of types the UI can actually render a switch
        // for. Accepting anything else wrote rows that no page ever showed and
        // no sender ever read — invisible dead state the user could not undo.
        // '*' is the one exception: it is the global digest opt-in.
        $allowedTypes = array_merge($this->catalog->typeKeys(), [self::DIGEST_TYPE]);

        $data = $request->validate([
            // Bounded so a single request cannot drive an unbounded
            // updateOrCreate loop inside one transaction. The column-level
            // "apply to every event" control sends one row per catalog type,
            // so the cap only has to clear the catalog itself.
            'preferences'                     => ['required', 'array', 'max:200'],
            'preferences.*.notification_type' => ['required', 'string', 'max:100', Rule::in($allowedTypes)],
            // REC-06 — `digest` is the opt-in for the daily unread-notification
            // email (NotificationDigestService, scheduled 07:05). Previously the
            // channel was rejected here, making the digest unreachable via API.
            'preferences.*.channel'           => ['required', 'in:in_app,email,digest'],
            'preferences.*.enabled'           => ['required', 'boolean'],
        ]);

        // NotificationDigestService only ever looks for a global opt-in row, so
        // a per-type digest preference is silently never honoured. Reject it
        // rather than storing a switch that does nothing.
        foreach ($data['preferences'] as $index => $row) {
            if ($row['channel'] === 'digest' && $row['notification_type'] !== self::DIGEST_TYPE) {
                throw ValidationException::withMessages([
                    "preferences.{$index}.channel" => 'The digest channel applies to all notifications and must use the "*" type.',
                ]);
            }

            if ($row['channel'] !== 'digest' && $row['notification_type'] === self::DIGEST_TYPE) {
                throw ValidationException::withMessages([
                    "preferences.{$index}.notification_type" => 'The "*" type is only valid for the digest channel.',
                ]);
            }
        }

        $this->service->updatePreferences($request->user(), $data['preferences']);

        return $this->preferencesIndex($request);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($request->user(), $id);
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function destroyAllRead(Request $request): JsonResponse
    {
        $count = $this->service->deleteAllRead($request->user());
        return response()->json(['data' => ['deleted' => $count]]);
    }
}
