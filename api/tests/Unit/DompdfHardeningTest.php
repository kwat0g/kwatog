<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * M011-F10 — the PDF renderer handles employee, payroll, accounting, and
 * supplier data. No official template needs Dompdf's inline-PHP or JavaScript
 * features, and leaving them on turns a template or data mistake into code
 * execution. Assert the hardened configuration so a future config edit that
 * re-enables them fails here rather than in production.
 */
class DompdfHardeningTest extends TestCase
{
    public function test_inline_php_execution_is_disabled(): void
    {
        $this->assertFalse(config('dompdf.options.enable_php'));
    }

    public function test_javascript_execution_is_disabled(): void
    {
        $this->assertFalse(config('dompdf.options.enable_javascript'));
    }

    public function test_remote_resource_loading_is_disabled(): void
    {
        // Remote loading is the SSRF surface: a template or a data field that
        // reaches the renderer must not be able to make the API fetch a URL.
        $this->assertFalse(config('dompdf.options.enable_remote'));
    }
}
