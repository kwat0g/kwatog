<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 (PDF foundation) — one Atelier brand context for every PDF.
 *
 * No DB: SettingsService is stubbed in-memory, Storage is faked.
 */
class PdfFoundationTest extends TestCase
{
    private function renderer(array $settings = []): PdfRenderService
    {
        $stub = new class($settings) extends SettingsService
        {
            public function __construct(private readonly array $map) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->map[$key] ?? $default;
            }
        };

        return new PdfRenderService($stub);
    }

    public function test_brand_context_uses_atelier_defaults_when_settings_empty(): void
    {
        $brand = $this->renderer()->brandContext();

        self::assertSame('#b4542a', $brand['accent']);
        self::assertSame('', $brand['logo_data_uri']);
        self::assertSame('', $brand['tagline']);
        self::assertSame('', $brand['terms']);
        self::assertSame('', $brand['name']);
    }

    public function test_brand_context_rejects_non_hex_accent(): void
    {
        $brand = $this->renderer(['pdf.accent_color' => 'red;drop-table'])->brandContext();

        self::assertSame('#b4542a', $brand['accent']);
    }

    public function test_brand_context_embeds_local_logo_as_data_uri(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        self::assertIsString($png);
        Storage::disk('local')->put('logos/test.png', $png);

        $brand = $this->renderer(['company.logo_path' => 'logos/test.png'])->brandContext();

        self::assertStringStartsWith('data:image/png;base64,', $brand['logo_data_uri']);
    }

    public function test_brand_context_hides_missing_logo_gracefully(): void
    {
        Storage::fake('local');

        $brand = $this->renderer(['company.logo_path' => 'logos/does-not-exist.png'])->brandContext();

        self::assertSame('', $brand['logo_data_uri']);
    }

    public function test_render_sets_pdf_metadata(): void
    {
        $bytes = $this->renderer()->render('pdf.demo', [
            'subject' => 'Foundation check',
            'reference' => 'X-1',
            'body' => 'Hello',
        ], ['title' => 'Foundation Check']);

        self::assertStringStartsWith('%PDF', $bytes);
        // CPDF stores info strings UTF-16BE; the markers prove add_info ran.
        self::assertStringContainsString('/Title (', $bytes);
        self::assertStringContainsString('/Author (', $bytes);
        self::assertStringContainsString('/Language (', $bytes);
    }

    public function test_letterhead_renders_logo_and_hides_missing_fields(): void
    {
        $html = view('pdf._components.letterhead', [
            'company' => [
                'name' => 'Acme Corp', 'address' => '', 'phone' => '', 'email' => '',
                'tin' => '', 'vat_status' => '', 'certification' => '',
                'logo_data_uri' => 'data:image/png;base64,AAA',
                'tagline' => 'Quality plastics',
            ],
            'docTitle' => 'Invoice',
            'generated' => ['at_text' => 'Oct 12, 2026', 'by' => 'system'],
        ])->render();

        self::assertStringContainsString('data:image/png;base64,AAA', $html);
        self::assertStringContainsString('Quality plastics', $html);
        self::assertStringNotContainsString('TIN:', $html);
    }

    public function test_doc_head_renders_status_and_references(): void
    {
        $html = view('pdf._components.doc_head', [
            'docNumber' => 'PO-202610-0015',
            'docStatus' => 'approved',
            'docDateLabel' => 'Order Date',
            'docDate' => 'Oct 12, 2026',
            'references' => ['PR-202610-0009', 'SO-202610-0003'],
        ])->render();

        self::assertStringContainsString('PO-202610-0015', $html);
        self::assertStringContainsString('APPROVED', $html);
        self::assertStringContainsString('PR-202610-0009', $html);
    }

    public function test_party_block_uses_dashes_never_null(): void
    {
        $html = view('pdf._components.party', [
            'title' => 'Vendor',
            'name' => 'Acme',
            'lines' => [null, '', 'Cavite'],
        ])->render();

        self::assertStringContainsString('Acme', $html);
        self::assertStringNotContainsString('null', $html);
    }

    public function test_totals_block_renders_amount_in_words(): void
    {
        $html = view('pdf._components.totals', [
            'rows' => [['label' => 'Total Amount', 'value' => '₱1,234.56', 'grand' => true]],
            'amountInWords' => 'One thousand two hundred thirty-four pesos and 56/100',
        ])->render();

        self::assertStringContainsString('₱1,234.56', $html);
        self::assertStringContainsString('One thousand', $html);
    }

    public function test_report_kit_renders_filter_summary_and_empty_state(): void
    {
        $summary = view('pdf._components.filter_summary', [
            'filters' => ['status' => 'approved', 'from' => '2026-01-01', 'to' => '2026-03-31'],
            'shown' => 37,
            'total' => 412,
        ])->render();
        self::assertStringContainsString('Showing 37 of 412', $summary);
        self::assertStringContainsString('status=approved', $summary);

        $empty = view('pdf._components.empty_state', [
            'message' => 'No records match these filters.',
        ])->render();
        self::assertStringContainsString('No records match these filters.', $empty);
    }
}
