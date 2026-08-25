<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Enums\ExportFormat;
use App\Common\Services\Export\ColumnSelectorService;
use App\Common\Services\Export\ExportColumnRegistry;
use App\Common\Services\Export\ExportRunner;
use App\Common\Services\Export\SpreadsheetExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series E (Task E2) — single export HTTP surface used by every list page.
 *
 *  GET /exports/{module}/columns   — list available + saved selection
 *  PUT /exports/{module}/columns   — save user's selection
 *  GET /exports/{module}/preview   — first 20 rows as JSON
 *  GET /exports/{module}/download  — stream the file
 */
class ExportController
{
    public function __construct(
        private readonly ColumnSelectorService $selector,
        private readonly ExportRunner $runner,
        private readonly SpreadsheetExportService $spreadsheets,
    ) {}

    public function columns(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);

        $available = ExportColumnRegistry::forUser($module, $request->user());
        $shape = [];
        foreach ($available as $key => $def) {
            $shape[] = [
                'key' => $key,
                'label' => $def['label'],
                'default' => (bool) ($def['default'] ?? false),
                'format' => $def['format'] ?? 'text',
            ];
        }

        return response()->json([
            'data' => [
                'module' => $module,
                'columns' => $shape,
                'selected' => $this->selector->resolve($request->user(), $module),
            ],
        ]);
    }

    public function saveColumns(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);
        $columns = $request->input('columns', []);
        abort_unless(is_array($columns), 422, 'The columns field must be an array.');
        $columns = ExportColumnRegistry::validateColumns($module, $columns, $request->user());
        $this->selector->save($request->user(), $module, $columns);

        return response()->json([
            'data' => [
                'module' => $module,
                'selected' => $this->selector->resolve($request->user(), $module),
            ],
        ]);
    }

    public function preview(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);
        $columns = $this->resolveColumnsFromRequest($request, $module);
        $filters = ExportColumnRegistry::validateFilters($module, (array) $request->query('filters', []));

        $rows = $this->runner->preview($module, $columns, $filters, 20, $request->user());

        return response()->json([
            'data' => [
                'columns' => $columns,
                'rows' => $rows,
            ],
        ]);
    }

    public function download(string $module, Request $request)
    {
        $this->guardModule($module, $request);

        $columns = $this->resolveColumnsFromRequest($request, $module);
        $filters = ExportColumnRegistry::validateFilters($module, (array) $request->query('filters', []));
        $format = ExportFormat::tryFrom((string) $request->query('format', 'xlsx')) ?? ExportFormat::Xlsx;

        $exporter = $this->runner->build($module, $columns, $filters, $request->user());
        $filename = sprintf(
            '%s-%s.%s',
            str_replace('.', '_', $module),
            now()->format('Ymd-His'),
            $format->extension(),
        );

        return $this->spreadsheets->download($exporter, $filename, $format);
    }

    /** @return array<int, string> */
    private function resolveColumnsFromRequest(Request $request, string $module): array
    {
        $raw = $request->query('columns');
        if (is_string($raw) && $raw !== '') {
            return ExportColumnRegistry::validateColumns(
                $module,
                explode(',', $raw),
                $request->user(),
            );
        }
        if (is_array($raw)) {
            return ExportColumnRegistry::validateColumns($module, $raw, $request->user());
        }

        return ExportColumnRegistry::validateColumns(
            $module,
            $this->selector->resolve($request->user(), $module),
            $request->user(),
        );
    }

    private function guardModule(string $module, Request $request): void
    {
        if (! ExportColumnRegistry::has($module)) {
            abort(404, "Unknown export module [{$module}].");
        }

        $perm = $this->permissionFor($module);
        $user = $request->user();
        abort_unless($user && $user->can($perm), 403);
        abort_unless($this->runner->supports($module), 404, "Export module [{$module}] is not implemented.");
    }

    private function permissionFor(string $module): string
    {
        return ExportColumnRegistry::permissionFor($module) ?? 'admin.audit_logs.view';
    }
}
