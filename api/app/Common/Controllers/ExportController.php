<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Exports\ExportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WS-E.1 — Generic export endpoint.
 *
 *   GET  /api/v1/exports                                  → list available resource keys
 *   GET  /api/v1/exports/{resource}?format=csv&...filters → stream the file
 *
 * Sync only in this slice — large exports already work because builders
 * stream via Eloquent cursor() so memory is flat. The queued path
 * (jobs/exports table/email-when-done) becomes a follow-up slice once
 * we have a real >50k-row resource that needs it.
 */
class ExportController
{
    public function __construct(private readonly ExportRegistry $registry) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->registry->keys()]);
    }

    public function download(Request $request, string $resource): StreamedResponse
    {
        if (! $this->registry->has($resource)) {
            throw new NotFoundHttpException("Unknown export resource [{$resource}].");
        }

        $builder = $this->registry->builder($resource);
        $user    = $request->user();
        abort_unless(
            $user !== null && $user->hasPermission($builder->permission()),
            403,
            "You don't have the {$builder->permission()} permission required to export this resource.",
        );

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv'], true)) {
            // XLSX / PDF will be wired up once Maatwebsite/Excel + the PDF
            // kit (WS-E.2) land. This slice ships CSV first.
            abort(422, "Unsupported export format [{$format}].");
        }

        $filters  = $request->except(['format']);
        $headers  = $builder->headers();
        $rows     = $builder->rows($filters, $user);
        $filename = $builder->filename().'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'wb');
            // BOM so Excel reads UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
