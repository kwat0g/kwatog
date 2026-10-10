<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Production\Models\WorkOrder;
use Carbon\Carbon;
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

    public function test_monthly_number_uses_the_document_date_not_wall_clock_month(): void
    {
        $code = app(DocumentSequenceService::class)->generate('journal_entry', Carbon::parse('2024-02-15'));

        $this->assertMatchesRegularExpression('/^JE-202402-\d{4}$/', $code);
    }

    public function test_stock_count_has_registered_monthly_sequence(): void
    {
        $code = app(DocumentSequenceService::class)->generate('stock_count');
        $this->assertMatchesRegularExpression('/^SC-\d{6}-\d{4}$/', $code);
    }

    public function test_work_order_sequence_advances_past_existing_imported_numbers(): void
    {
        $prefix = 'WO-'.now()->format('Ym').'-';
        WorkOrder::factory()->create(['wo_number' => $prefix.'0001']);
        $this->assertSame($prefix.'0002', app(DocumentSequenceService::class)->generate('work_order'));
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentSequenceService::class)->generate('not_a_real_type');
    }
}
