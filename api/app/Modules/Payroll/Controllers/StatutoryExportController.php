<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Common\Enums\ExportFormat;
use App\Common\Services\Export\SpreadsheetExportService;
use App\Modules\Payroll\Exports\Government\SssR3Export;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\Statutory\Bir1601CService;
use App\Modules\Payroll\Services\Statutory\Bir1604CfService;
use App\Modules\Payroll\Services\Statutory\PagibigMcrfService;
use App\Modules\Payroll\Services\Statutory\PhilhealthRf1Service;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatutoryExportController
{
    private function csv(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bir1601c(Request $request, Bir1601CService $service): Response
    {
        abort_unless($request->user()?->can('payroll.statutory.export'), 403);
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        $data = $service->generate($year, $month);

        return $this->csv($service->toCsv($data), sprintf('BIR-1601-C-%04d-%02d.csv', $year, $month));
    }

    public function philhealthRf1(Request $request, PhilhealthRf1Service $service): Response
    {
        abort_unless($request->user()?->can('payroll.statutory.export'), 403);
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return $this->csv($service->toCsv($service->generate($year, $month)),
            sprintf('PhilHealth-RF1-%04d-%02d.csv', $year, $month));
    }

    public function pagibigMcrf(Request $request, PagibigMcrfService $service): Response
    {
        abort_unless($request->user()?->can('payroll.statutory.export'), 403);
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return $this->csv($service->toCsv($service->generate($year, $month)),
            sprintf('PagIBIG-MCRF-%04d-%02d.csv', $year, $month));
    }

    public function bir1604cf(Request $request, Bir1604CfService $service): Response
    {
        abort_unless($request->user()?->can('payroll.statutory.export'), 403);
        $year = (int) $request->query('year', now()->year);

        return $this->csv($service->toCsv($service->generate($year)), sprintf('BIR-1604-CF-%04d.csv', $year));
    }

    /**
     * REC-06 — SSS R-3 (Contribution Collection List). Unlike the other
     * statutory exports (which aggregate by calendar month/year), the SSS R-3
     * exporter is per-PayrollPeriod and produces an XLSX workbook,
     * so the period is resolved via route-model binding here rather than
     * year/month query params. The exporter guards status internally
     * (finalized/disbursed only); a missing period yields a clean 404 from the
     * binding.
     */
    public function sssR3(Request $request, PayrollPeriod $period, SpreadsheetExportService $spreadsheets): Response
    {
        abort_unless($request->user()?->can('payroll.statutory.export'), 403);

        return $spreadsheets->download(
            new SssR3Export($period),
            sprintf('SSS-R3-%s.xlsx', $period->period_start?->format('Y-m') ?? 'period'),
            ExportFormat::Xlsx,
        );
    }
}
