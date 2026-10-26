<?php

declare(strict_types=1);

namespace App\Common\Services\Pdf;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Series E (Task E1) — single entry point for every PDF rendered in the
 * system. Pre-injects company branding, generator metadata, and watermark
 * flag into the view so per-document Blades stay focused on body content.
 *
 * Phase 2 (PDF foundation) — brand chrome is Atelier (warm paper, espresso
 * ink, clay accent). The accent/tagline/terms/logo all resolve from settings
 * with graceful fallbacks so a missing setting hides its element instead of
 * breaking the render.
 */
class PdfRenderService
{
    /** Atelier clay — fallback when `pdf.accent_color` is unset or invalid. */
    public const DEFAULT_ACCENT = '#b4542a';

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
        // Most callers never pass a generator; without this fallback every
        // document printed from the UI read "Issued By: system".
        $actor        = auth()->user();
        $generator    = $opts['generator']     ?? ($actor instanceof User ? $actor : null);
        $title        = $opts['title']         ?? null;
        $watermark    = $opts['watermark_text'] ?? ($confidential ? 'CONFIDENTIAL' : null);

        $companyCtx = $this->companyContext();
        $brandCtx = $this->brandContext();

        $merged = array_merge($data, [
            'company'        => array_merge($companyCtx, $brandCtx),
            'brand'          => $brandCtx,
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

        $pdf = Pdf::loadView($view, $merged)->setPaper($paper, $orientation);
        $this->applyMetadata($pdf, $title, $companyCtx['name']);

        return $pdf->output();
    }

    /**
     * Public brand context so tests and the branding preview can resolve the
     * same values the chrome renders — one source of truth, no re-expression.
     *
     * @return array{accent: string, logo_data_uri: string, tagline: string, terms: string, name: string}
     */
    public function brandContext(): array
    {
        return [
            'accent' => $this->accent(),
            'logo_data_uri' => $this->logoDataUri(),
            'tagline' => $this->setting('company.tagline'),
            'terms' => $this->setting('pdf.terms_conditions'),
            'name' => $this->setting('company.legal_name'),
        ];
    }

    /** Admin-set accent, restricted to a 6-digit hex so it cannot inject CSS. */
    private function accent(): string
    {
        $raw = trim($this->setting('pdf.accent_color'));

        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/D', $raw) ? $raw : self::DEFAULT_ACCENT;
    }

    /**
     * Local logo file → data URI (DomPDF has `enable_remote=false`, so a URL
     * would never load). Missing/unreadable/non-image → '' and the letterhead
     * hides the <img> gracefully.
     */
    private function logoDataUri(): string
    {
        try {
            $path = trim($this->setting('company.logo_path'));
            if ($path === '') {
                return '';
            }
            $disk = \Illuminate\Support\Facades\Storage::disk('local');
            if (! $disk->exists($path)) {
                return '';
            }
            $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };
            if ($mime === null) {
                return '';
            }
            $bytes = $disk->get($path);
            if (! is_string($bytes) || $bytes === '') {
                return '';
            }

            return "data:{$mime};base64,".base64_encode($bytes);
        } catch (\Throwable $exception) {
            Log::warning('PDF logo lookup failed; omitting logo.', [
                'exception' => $exception,
            ]);

            return '';
        }
    }

    private function applyMetadata(mixed $pdf, ?string $title, string $author): void
    {
        try {
            $dompdf = $pdf->getDomPDF();
            $dompdf->add_info('Title', $title ?? 'Document');
            $dompdf->add_info('Author', $author !== '' ? $author : 'OGAMI ERP');
            $dompdf->add_info('Subject', $title ?? 'Document');
            $dompdf->add_info('Creator', 'OGAMI ERP');
            $dompdf->add_info('Language', 'en-PH');
        } catch (\Throwable $exception) {
            Log::warning('PDF metadata could not be applied.', [
                'exception' => $exception,
            ]);
        }
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
        } catch (\Throwable $exception) {
            Log::warning('PDF branding setting lookup failed; using an empty value.', [
                'setting' => $key,
                'exception' => $exception,
            ]);
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
            'at_text' => CarbonImmutable::now()->format('M d, Y g:i A'),
        ];
    }
}
