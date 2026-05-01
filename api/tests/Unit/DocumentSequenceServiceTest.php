<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_sequential_monthly_numbers(): void
    {
        $svc = app(DocumentSequenceService::class);
        $a = $svc->generate('purchase_order');
        $b = $svc->generate('purchase_order');

        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('PO-', $a);
        $this->assertMatchesRegularExpression('/^PO-\d{6}-\d{4}$/', $a);
    }

    public function test_yearly_format_for_employee(): void
    {
        $svc = app(DocumentSequenceService::class);
        $code = $svc->generate('employee');
        $this->assertMatchesRegularExpression('/^OGM-\d{4}-\d{4}$/', $code);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentSequenceService::class)->generate('not_a_real_type');
    }
}
