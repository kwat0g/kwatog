<?php

declare(strict_types=1);

namespace App\Common\Services\Pdf;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Series E (Task E1) — single entry point for every PDF rendered in the
 * system. Pre-injects company branding, generator metadata, and watermark
 * flag into the view so per-document Blades stay focused on body content.
 */
class PdfRenderService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  string  $view    Blade view name, e.g. 'pdf.payslip'
     * @param  array<string, mixed>  $data  view-specific data
     * @param  array{
     *     paper?: string,
     *     orientation?: 'portrait'|'landscape',
     *     confidential?: bool,
     *     generator?: \App\Modules\Auth\Models\User|null,
     *     title?: string,
     *     watermark_text?: string,
     * }  $opts
     * @return string  PDF binary bytes
     */
    public function render(string $view, array $data = [], array $opts = []): string
    {
        $this->ensureDompdfDirs();

        $paper        = $opts['paper']         ?? 'a4';
        $orientation  = $opts['orientation']   ?? 'portrait';
        $confidential = (bool) ($opts['confidential'] ?? false);
        $generator    = $opts['generator']     ?? null;
        $title        = $opts['title']         ?? null;
        $watermark    = $opts['watermark_text'] ?? ($confidential ? 'CONFIDENTIAL' : null);

        $companyCtx = $this->companyContext();

        $merged = array_merge($data, [
            'company'        => $companyCtx,
            'generated'      => $this->generatedContext($generator),
            'confidential'   => $confidential,
            'watermark'      => $watermark,
            'docTitle'       => $title,
            // Legacy keys still consumed by some Blades.
            'companyName'    => $companyCtx['name'],
            'companyAddress' => $companyCtx['address'],
            'companyTin'     => $companyCtx['tin'],
            'user'           => $generator?->name,
        ]);

        return Pdf::loadView($view, $merged)
            ->setPaper($paper, $orientation)
            ->output();
    }

    /**
     * Make sure every directory the PDF render path needs actually exists and
     * is writable before the first render:
     *
     *   - storage/fonts            (dompdf font cache)
     *   - storage/app/dompdf-tmp   (dompdf temp render dir)
     *   - storage/framework/views  (Blade compiled views — the most common
     *                               cause of "Please provide a valid cache
     *                               path" thrown by Illuminate\View\Compilers
     *                               on a freshly-cloned repo)
     *
     * Idempotent — only mkdir if missing. 0775 so the running user + group
     * can read/write/exec.
     */
    private function ensureDompdfDirs(): void
    {
        foreach ([
            (string) config('dompdf.options.font_dir',   storage_path('fonts')),
            (string) config('dompdf.options.font_cache', storage_path('fonts')),
            (string) config('dompdf.options.temp_dir',   storage_path('app/dompdf-tmp')),
            (string) config('view.compiled',             storage_path('framework/views')),
        ] as $dir) {
            if ($dir === '' || is_dir($dir)) continue;
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function companyContext(): array
    {
        return [
            // Company identity is deployment data. Never substitute a
            // hardcoded entity when a setting is absent.
            'name'       => $this->setting('company.legal_name'),
            'address'    => $this->setting('company.address'),
            'phone'      => $this->setting('company.phone'),
            'email'      => $this->setting('company.email'),
            'tin'        => $this->setting('company.tin'),
            'vat_status' => $this->setting('company.vat_status'),
            'logo_path'  => $this->setting('company.logo_path'),
            'public_url' => $this->setting('company.public_url'),
            'certification' => $this->setting('company.certification'),
            'disclaimer' => $this->setting('pdf.footer_disclaimer'),
        ];
    }

    private function setting(string $key): string
    {
        try {
            $val = $this->settings->get($key);
            return is_string($val) && trim($val) !== '' ? $val : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedContext(?User $generator): array
    {
        return [
            'by'      => $generator?->name ?? 'system',
            'by_user' => $generator,
            'at'      => CarbonImmutable::now(),
            'at_text' => CarbonImmutable::now()->format('M d, Y H:i'),
        ];
    }
}
