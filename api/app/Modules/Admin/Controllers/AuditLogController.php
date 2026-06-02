<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Common\Support\AuditFieldLabels;
use App\Common\Support\SearchOperator;
use App\Modules\Admin\Resources\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filteredQuery($request)->with(['user:id,name,email,role_id', 'user.role:id,name,slug']);

        $perPage = min((int) ($request->integer('per_page') ?: 25), 100);

        return AuditLogResource::collection($query->paginate($perPage));
    }

    /**
     * Sprint 8 — Task 79. Show a single audit row with field-level diff.
     * Sprint P7 — diff rows now carry `label` and `type` so the SPA can
     * render "Changed Monthly Salary from ₱18,000.00 to ₱20,000.00".
     */
    public function show(string $id): JsonResponse
    {
        $decoded = AuditLog::tryDecodeHash($id) ?? (ctype_digit($id) ? (int) $id : null);
        abort_if($decoded === null, 404);
        $log = AuditLog::query()->with(['user:id,name,email,role_id', 'user.role:id,name,slug'])->findOrFail($decoded);
        $diff = $this->buildDiff(
            (string) $log->model_type,
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
                    'role'  => $log->user->role ? [
                        'name' => $log->user->role->name,
                        'slug' => $log->user->role->slug,
                    ] : null,
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
     * Sprint P7 — stream a CSV of the same filtered query as `index()`.
     * Capped at 50,000 rows to bound memory; chunked via `lazy()`.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request)->with('user:id,name,email');

        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'timestamp', 'user', 'email', 'ip', 'action',
                'model', 'model_id', 'summary',
            ]);

            $count = 0;
            $cap = 50_000;
            foreach ($query->lazy(500) as $row) {
                if ($count++ >= $cap) break;
                fputcsv($out, [
                    optional($row->created_at)?->toIso8601String() ?? '',
                    $row->user?->name ?? '',
                    $row->user?->email ?? '',
                    $row->ip_address ?? '',
                    (string) $row->action,
                    self::basename((string) $row->model_type),
                    (string) ($row->model_id ?? ''),
                    self::summary(
                        (string) $row->model_type,
                        (string) $row->action,
                        (array) ($row->old_values ?? []),
                        (array) ($row->new_values ?? []),
                    ),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Apply the same filter set as the index page but return the underlying
     * Eloquent builder so both `index` and `export` can reuse it.
     */
    private function filteredQuery(Request $request)
    {
        $query = AuditLog::query()->orderByDesc('id');

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
        return $query;
    }

    /**
     * Build a JSON-friendly per-key diff with human-readable labels and
     * type metadata (used by the SPA for money/date/enum/encrypted formatting).
     *
     * Each row:
     *   { kind:'added'|'removed'|'changed', key, label, type, old?, new? }
     */
    private function buildDiff(string $modelType, array $old, array $new): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $rows = [];
        foreach ($keys as $key) {
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);
            $meta   = AuditFieldLabels::field($modelType, $key);
            $label  = $meta['label'] ?? self::humanize($key);
            $type   = $meta['type']  ?? 'text';

            // Encrypted fields: never expose the cleartext, even if it's in
            // old/new values from a logged write. Just show "(changed)".
            if ($type === 'encrypted') {
                if ($hasOld && ! $hasNew) {
                    $rows[] = ['kind' => 'removed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => null];
                } elseif (! $hasOld && $hasNew) {
                    $rows[] = ['kind' => 'added',   'key' => $key, 'label' => $label, 'type' => $type, 'new' => null];
                } elseif ($old[$key] !== $new[$key]) {
                    $rows[] = ['kind' => 'changed', 'key' => $key, 'label' => $label, 'type' => $type];
                }
                continue;
            }

            if ($hasOld && ! $hasNew) {
                $rows[] = ['kind' => 'removed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => $old[$key]];
            } elseif (! $hasOld && $hasNew) {
                $rows[] = ['kind' => 'added',   'key' => $key, 'label' => $label, 'type' => $type, 'new' => $new[$key]];
            } elseif ($old[$key] !== $new[$key]) {
                $rows[] = ['kind' => 'changed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => $old[$key], 'new' => $new[$key]];
            }
        }
        return $rows;
    }

    /**
     * One-line CSV summary for an audit row.
     * Examples:
     *   "Created Employee #142"
     *   "Updated Employee: changed Monthly Salary, status"
     *   "Deleted PurchaseOrder #15"
     */
    private static function summary(string $modelType, string $action, array $old, array $new): string
    {
        $base = self::basename($modelType);
        if ($action === 'created') {
            return "Created {$base}";
        }
        if ($action === 'deleted') {
            return "Deleted {$base}";
        }
        // updated — list changed fields by label.
        $changed = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            if (($old[$k] ?? null) !== ($new[$k] ?? null)) {
                $meta = AuditFieldLabels::field($modelType, $k);
                $changed[] = $meta['label'] ?? self::humanize($k);
            }
        }
        if (count($changed) === 0) return "Updated {$base}";
        return "Updated {$base}: changed " . implode(', ', array_slice($changed, 0, 8))
            . (count($changed) > 8 ? ', …' : '');
    }

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private static function basename(string $type): string
    {
        $pos = strrpos($type, '\\');
        return $pos === false ? $type : substr($type, $pos + 1);
    }
}
