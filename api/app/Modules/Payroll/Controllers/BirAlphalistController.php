<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Services\BirAlphalistService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BirAlphalistController
{
    public function __construct(private readonly BirAlphalistService $service) {}

    /**
     * Download the BIR 2316 Alphalist CSV for a given year.
     *
     * Query params:
     *   year  — integer (defaults to current year)
     */
    public function download(Request $request): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);

        $year = (int) $request->query('year', now()->year);
        $data = $this->service->generate($year);
        $csv  = $this->service->toCsv($data);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"BIR-2316-Alphalist-{$year}.csv\"",
        ]);
    }
}
