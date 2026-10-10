<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalNotificationController
{
    /** The guard selects the realm; numeric IDs alone cannot identify a notification recipient. */
    private function query(Request $request): Builder
    {
        $user = $request->user();
        abort_unless($user instanceof CustomerPortalUser || $user instanceof SupplierPortalUser, 401);
        return DB::table('notifications')->where('notifiable_type', $user::class)->where('notifiable_id', $user->id);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);
        $query = $this->query($request);
        if ($request->boolean('unread_only')) $query->whereNull('read_at');
        if (! empty($filters['type'])) $query->where('type', $filters['type']);
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        return response()->json([
            'data' => collect($rows->items())->map(static fn ($row): array => [
                'id' => $row->id, 'type' => $row->type,
                'data' => is_string($row->data) ? json_decode($row->data, true) : $row->data,
                'read_at' => $row->read_at, 'created_at' => $row->created_at,
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(), 'total' => $rows->total(),
                'unread_count' => $this->query($request)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $readAt = now();
        abort_unless($this->query($request)->where('id', $id)->update(['read_at' => $readAt]), 404);
        return response()->json(['data' => ['id' => $id, 'read_at' => $readAt->toISOString()]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['data' => ['marked_read' => $count]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        abort_unless($this->query($request)->where('id', $id)->delete(), 404);
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function clearRead(Request $request): JsonResponse
    {
        return response()->json(['data' => ['deleted' => $this->query($request)->whereNotNull('read_at')->delete()]]);
    }
}
