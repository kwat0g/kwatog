<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Common\Support\SearchOperator;
use App\Modules\Admin\Resources\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('user:id,name,email,role_id')->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('model_type')) {
            $query->where('model_type', SearchOperator::like(), '%'.$request->string('model_type').'%');
        }
        if ($request->filled('user_id')) {
            $raw = $request->string('user_id')->toString();
            $userId = ctype_digit($raw)
                ? (int) $raw
                : \App\Modules\Auth\Models\User::tryDecodeHash($raw);
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $perPage = min((int) ($request->integer('per_page') ?: 25), 100);

        return AuditLogResource::collection($query->paginate($perPage));
    }

    /**
     * Sprint 8 — Task 79. Show a single audit row with field-level diff.
     */
    public function show(string $id): JsonResponse
    {
        $decoded = AuditLog::tryDecodeHash($id) ?? (ctype_digit($id) ? (int) $id : null);
        abort_if($decoded === null, 404);
        $log = AuditLog::query()->with('user:id,name,email,role_id')->findOrFail($decoded);
        $diff = $this->buildDiff(
            (array) ($log->old_values ?? []),
            (array) ($log->new_values ?? []),
        );
        return response()->json([
            'data' => [
                'id'         => $log->hash_id,
                'action'     => $log->action,
                'model_type' => $log->model_type,
                'model_id'   => $log->model_id,
                'user'       => $log->user ? [
                    'id'    => $log->user->hash_id,
                    'name'  => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => optional($log->created_at)?->toISOString(),
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'diff'       => $diff,
            ],
        ]);
    }

    /**
     * Build a JSON-friendly per-key diff. Each entry is one of:
     *   { kind:'added',    key, new }
     *   { kind:'removed',  key, old }
     *   { kind:'changed',  key, old, new }
     *   { kind:'unchanged',key, value }       (only emitted for very small payloads)
     */
    private function buildDiff(array $old, array $new): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $rows = [];
        foreach ($keys as $key) {
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);
            if ($hasOld && ! $hasNew) {
                $rows[] = ['kind' => 'removed', 'key' => $key, 'old' => $old[$key]];
            } elseif (! $hasOld && $hasNew) {
                $rows[] = ['kind' => 'added',   'key' => $key, 'new' => $new[$key]];
            } elseif ($old[$key] !== $new[$key]) {
                $rows[] = ['kind' => 'changed', 'key' => $key, 'old' => $old[$key], 'new' => $new[$key]];
            }
        }
        return $rows;
    }
}
