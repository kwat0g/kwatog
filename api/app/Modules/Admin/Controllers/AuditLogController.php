<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Common\Support\AuditFieldLabels;
use App\Common\Support\SearchOperator;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use App\Modules\Admin\Resources\AuditLogResource;
use App\Modules\Admin\Support\AuditDiffBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController
{
    public function options(): JsonResponse
    {
        $actions = AuditLog::query()->whereNotNull('action')->distinct()->orderBy('action')->pluck('action')
            ->map(static fn ($action): array => ['value' => (string) $action, 'label' => ucfirst((string) $action)])->values();
        $actors = User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(static fn (User $user): array => [
                'value' => $user->hash_id,
                'label' => $user->name.' ('.$user->email.')',
            ])
            ->values();

        return response()->json(['data' => ['actions' => $actions, 'actors' => $actors]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->validatedFilters($request);
        $query = $this->filteredQuery($filters)->with(['user:id,name,email,role_id', 'user.role:id,name,slug']);

        $perPage = (int) ($filters['per_page'] ?? 25);

        return AuditLogResource::collection($query->paginate($perPage));
    }

    /**
     * Sprint 8 — Task 79. Show a single audit row with field-level diff.
     * Sprint P7 — diff rows now carry `label` and `type` so the SPA can
     * render "Changed Monthly Salary from ₱18,000.00 to ₱20,000.00".
     */
    public function show(string $id): JsonResponse
    {
        $decoded = $this->decodePublicId($id, AuditLog::class);
        abort_if($decoded === null, 404);
        $log = AuditLog::query()->with(['user:id,name,email,role_id', 'user.role:id,name,slug'])->findOrFail($decoded);
        $diff = AuditDiffBuilder::build(
            (string) $log->model_type,
            (array) ($log->old_values ?? []),
            (array) ($log->new_values ?? []),
        );
        return response()->json([
            'data' => [
                'id'         => $log->hash_id,
                'action'     => $log->action,
                'model_type' => $log->model_type,
                'model_id'   => $log->model_id !== null ? app('hashids')->encode((int) $log->model_id) : null,
                'actor_type' => $log->actor_type,
                'source_command' => $log->source_command,
                'correlation_id' => $log->correlation_id,
                'reason' => $log->reason,
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
        $query = $this->filteredQuery($this->validatedFilters($request))->with('user:id,name,email');

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
                    $row->model_id !== null ? app('hashids')->encode((int) $row->model_id) : '',
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
     * Entity-scoped audit trail — "show me all changes to PO-202604-0015".
     * IATF 16949 compliance: full traceability per record across all actions.
     *
     * Accepts model_type (class basename or FQCN) + model_id (hashid or int).
     */
    public function entityTrail(Request $request): AnonymousResourceCollection
    {
        $params = $request->validate([
            'model_type' => ['required', 'string', 'max:100'],
            'model_id' => ['required', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $modelType = $params['model_type'];
        $modelId   = $params['model_id'];

        $numericId = $this->decodePublicId((string) $modelId);
        abort_if($numericId === null, 422, 'Invalid model_id');

        $query = AuditLog::query()
            ->where(function ($q) use ($modelType) {
                // Match exact FQCN or basename (e.g. "PurchaseOrder" matches
                // "App\Modules\Purchasing\Models\PurchaseOrder").
                $q->where('model_type', $modelType)
                  ->orWhere('model_type', SearchOperator::like(), '%\\'.$modelType);
            })
            ->where('model_id', $numericId)
            ->with(['user:id,name,email,role_id', 'user.role:id,name,slug'])
            ->orderByDesc('created_at');

        return AuditLogResource::collection($query->paginate((int) ($params['per_page'] ?? 25)));
    }

    /**
     * PDF export of audit logs — same filter set as index().
     * Capped at 500 rows to keep PDF rendering performant.
     */
    public function exportPdf(Request $request, PdfRenderService $pdfService): \Illuminate\Http\Response
    {
        $filters = $this->validatedFilters($request);
        $logs = $this->filteredQuery($filters)
            ->with('user:id,name,email')
            ->limit(500)
            ->get();

        $filterSummary = collect($filters)->only([
            'model_type', 'user_id', 'action', 'from', 'to',
        ])->filter()->map(fn ($v, $k) => "{$k}={$v}")->implode(', ') ?: 'None';

        $bytes = $pdfService->render('pdf.audit-log', [
            'logs'          => $logs,
            'filterSummary' => $filterSummary,
        ], [
            'orientation' => 'landscape',
            'title'       => 'Audit Trail Report',
        ]);

        $filename = 'audit-trail-'.now()->format('Ymd-His').'.pdf';

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Apply the same filter set as the index page but return the underlying
     * Eloquent builder so both `index` and `export` can reuse it.
     */
    /** @param array<string, mixed> $filters */
    private function filteredQuery(array $filters)
    {
        $query = AuditLog::query()->orderByDesc('id');

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }
        if (! empty($filters['model_type'])) {
            $query->where('model_type', SearchOperator::like(), '%'.(string) $filters['model_type'].'%');
        }
        if (! empty($filters['user_id'])) {
            $userId = $this->decodePublicId((string) $filters['user_id'], User::class);
            $userId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('user_id', $userId);
        }
        if (! empty($filters['model_id'])) {
            $modelId = $this->decodePublicId((string) $filters['model_id']);
            $modelId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('model_id', $modelId);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse((string) $filters['to'])->endOfDay());
        }
        return $query;
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', 'max:50'],
            'model_type' => ['nullable', 'string', 'max:100'],
            'model_id' => ['nullable', 'string', 'max:128'],
            'user_id' => ['nullable', 'string', 'max:128'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function decodePublicId(string $value, string $modelClass = AuditLog::class): ?int
    {
        if (app()->environment('testing') && ctype_digit($value)) {
            return (int) $value;
        }

        return $modelClass::tryDecodeHash($value);
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
