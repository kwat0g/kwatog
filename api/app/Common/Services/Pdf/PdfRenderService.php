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

        $merged = array_merge($data, [
            'company'      => $this->companyContext(),
            'generated'    => $this->generatedContext($generator),
            'confidential' => $confidential,
            'watermark'    => $watermark,
            'docTitle'     => $title,
            // Legacy keys still consumed by some Blades.
            'companyName'    => $this->settings->get('company.legal_name', 'Philippine Ogami Corporation'),
            'companyAddress' => $this->settings->get('company.address', ''),
            'companyTin'     => $this->settings->get('company.tin', ''),
            'user'           => $generator?->name,
        ]);

        return Pdf::loadView($view, $merged)
            ->setPaper($paper, $orientation)
            ->output();
    }

    /**
     * Make sure the dompdf font cache and temp directories actually exist and
     * are writable before the first render. The dompdf config points at
     * storage/fonts/ and storage/app/dompdf-tmp/; on freshly-cloned repos
     * (and in containers where storage/ is volume-mounted from outside)
     * those directories aren't created by Laravel's default skeleton.
     *
     * The cost of failing to mkdir is "Please provide a valid cache path"
     * deep inside dompdf's font loader, which is what the Series E seeder
     * was hitting on first run. Idempotent — only mkdir if missing.
     */
    private function ensureDompdfDirs(): void
    {
        foreach ([
            (string) config('dompdf.options.font_dir', storage_path('fonts')),
            (string) config('dompdf.options.font_cache', storage_path('fonts')),
            (string) config('dompdf.options.temp_dir', storage_path('app/dompdf-tmp')),
        ] as $dir) {
            if ($dir === '' || is_dir($dir)) continue;
            // 0775 so the running user + group can read/write/exec.
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function companyContext(): array
    {
        return [
            'name'       => (string) $this->settings->get('company.legal_name', 'Philippine Ogami Corporation'),
            'address'    => (string) $this->settings->get('company.address', 'FCIE, Dasmariñas, Cavite'),
            'phone'      => (string) $this->settings->get('company.phone', ''),
            'email'      => (string) $this->settings->get('company.email', ''),
            'tin'        => (string) $this->settings->get('company.tin', ''),
            'vat_status' => (string) $this->settings->get('company.vat_status', 'VAT Registered'),
            'logo_path'  => (string) $this->settings->get('company.logo_path', ''),
            'public_url' => (string) $this->settings->get('company.public_url', ''),
            'disclaimer' => (string) $this->settings->get('pdf.footer_disclaimer', ''),
        ];
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
