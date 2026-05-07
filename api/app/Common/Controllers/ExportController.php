<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Enums\ExportFormat;
use App\Common\Services\Export\ColumnSelectorService;
use App\Common\Services\Export\ExportColumnRegistry;
use App\Common\Services\Export\ExportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

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
    ) {}

    public function columns(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);

        $available = ExportColumnRegistry::for($module);
        $shape = [];
        foreach ($available as $key => $def) {
            $shape[] = [
                'key'     => $key,
                'label'   => $def['label'],
                'default' => (bool) ($def['default'] ?? false),
                'format'  => $def['format'] ?? 'text',
            ];
        }

        return response()->json([
            'data' => [
                'module'   => $module,
                'columns'  => $shape,
                'selected' => $this->selector->resolve($request->user(), $module),
            ],
        ]);
    }

    public function saveColumns(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);
        $columns = $request->input('columns', []);
        if (! is_array($columns)) {
            $columns = [];
        }
        $this->selector->save($request->user(), $module, array_values(array_filter($columns, 'is_string')));

        return response()->json([
            'data' => [
                'module'   => $module,
                'selected' => $this->selector->resolve($request->user(), $module),
            ],
        ]);
    }

    public function preview(string $module, Request $request): JsonResponse
    {
        $this->guardModule($module, $request);
        $columns = $this->resolveColumnsFromRequest($request, $module);
        $filters = (array) $request->query('filters', []);

        $rows = $this->runner->preview($module, $columns, $filters, 20);

        return response()->json([
            'data' => [
                'columns' => $columns,
                'rows'    => $rows,
            ],
        ]);
    }

    public function download(string $module, Request $request)
    {
        $this->guardModule($module, $request);

        $columns = $this->resolveColumnsFromRequest($request, $module);
        $filters = (array) $request->query('filters', []);
        $format  = ExportFormat::tryFrom((string) $request->query('format', 'xlsx')) ?? ExportFormat::Xlsx;

        $exporter = $this->runner->build($module, $columns, $filters);
        $filename = sprintf(
            '%s-%s.%s',
            str_replace('.', '_', $module),
            now()->format('Ymd-His'),
            $format->extension(),
        );

        $writer = $format === ExportFormat::Csv ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;

        return Excel::download($exporter, $filename, $writer, [
            'Content-Type' => $format->mimeType(),
        ]);
    }

    /** @return array<int, string> */
    private function resolveColumnsFromRequest(Request $request, string $module): array
    {
        $raw = $request->query('columns');
        if (is_string($raw) && $raw !== '') {
            return array_values(array_filter(explode(',', $raw), fn ($s) => $s !== ''));
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }
        return $this->selector->resolve($request->user(), $module);
    }

    private function guardModule(string $module, Request $request): void
    {
        if (! ExportColumnRegistry::has($module)) {
            abort(404, "Unknown export module [{$module}].");
        }

        $perm = $this->permissionFor($module);
        $user = $request->user();
        abort_unless($user && $user->can($perm), 403);
    }

    private function permissionFor(string $module): string
    {
        return match ($module) {
            'hr.employees'           => 'hr.employees.export',
            'payroll.register',
            'payroll.gov.sss_r3',
            'payroll.gov.philhealth_rf1',
            'payroll.gov.pagibig',
            'payroll.gov.bir_1601c'  => 'payroll.view',
            'inventory.valuation',
            'inventory.stock_card'   => 'inventory.view',
            'accounting.ar_aging',
            'accounting.ap_aging'    => 'accounting.view',
            default                  => 'admin.audit_logs.view',
        };
    }
}
