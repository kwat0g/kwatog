<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Services\Statutory\Bir1601CService;
use App\Modules\Payroll\Services\Statutory\PhilhealthRf1Service;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatutoryExportController
{
    private function csv(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bir1601c(Request $request, Bir1601CService $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        $data  = $service->generate($year, $month);

        return $this->csv($service->toCsv($data), sprintf('BIR-1601-C-%04d-%02d.csv', $year, $month));
    }

    public function philhealthRf1(Request $request, PhilhealthRf1Service $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return $this->csv($service->toCsv($service->generate($year, $month)),
            sprintf('PhilHealth-RF1-%04d-%02d.csv', $year, $month));
    }
}
