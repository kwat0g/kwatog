<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentSequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_generation_increments_correctly(): void
    {
        $svc = app(DocumentSequenceService::class);
        $numbers = [];

        for ($i = 0; $i < 10; $i++) {
            $numbers[] = $svc->generate('purchase_order');
        }

        // All should be unique
        $this->assertCount(10, array_unique($numbers));

        // Extract the sequence portion and verify sequential ordering
        $sequences = array_map(function (string $number) {
            $parts = explode('-', $number);
            return (int) end($parts);
        }, $numbers);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($i + 1, $sequences[$i]);
        }
    }

    public function test_different_prefixes_are_independent(): void
    {
        $svc = app(DocumentSequenceService::class);

        $po1 = $svc->generate('purchase_order');
        $inv1 = $svc->generate('invoice');
        $po2 = $svc->generate('purchase_order');
        $inv2 = $svc->generate('invoice');

        // PO should be 0001 and 0002
        $this->assertStringEndsWith('0001', $po1);
        $this->assertStringEndsWith('0002', $po2);

        // INV should independently be 0001 and 0002
        $this->assertStringEndsWith('0001', $inv1);
        $this->assertStringEndsWith('0002', $inv2);

        // Prefixes are correct
        $this->assertStringStartsWith('PO-', $po1);
        $this->assertStringStartsWith('INV-', $inv1);
    }

    public function test_month_reset_starts_from_one(): void
    {
        $svc = app(DocumentSequenceService::class);

        // Generate a number in the current month
        $this->travelTo(now()->startOfMonth());
        $first = $svc->generate('purchase_order');
        $this->assertStringEndsWith('0001', $first);

        // Generate another in the same month
        $second = $svc->generate('purchase_order');
        $this->assertStringEndsWith('0002', $second);

        // Travel to next month — counter should reset
        $this->travelTo(now()->addMonth()->startOfMonth());
        $resetFirst = $svc->generate('purchase_order');
        $this->assertStringEndsWith('0001', $resetFirst);

        // The date part should differ between months
        $firstDatePart = explode('-', $first)[1];
        $resetDatePart = explode('-', $resetFirst)[1];
        $this->assertNotSame($firstDatePart, $resetDatePart);
    }

    public function test_concurrent_generation_produces_unique_numbers(): void
    {
        $svc = app(DocumentSequenceService::class);
        $numbers = [];

        // Rapidly generate 50 numbers to stress the SELECT FOR UPDATE pattern.
        // While this is not true parallelism, it verifies that the locking
        // mechanism correctly increments under rapid sequential access and
        // that no duplicates slip through.
        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $svc->generate('work_order');
        }

        // All 50 must be unique
        $this->assertCount(50, array_unique($numbers));

        // Verify the sequence is monotonically increasing
        $sequences = array_map(function (string $number) {
            $parts = explode('-', $number);
            return (int) end($parts);
        }, $numbers);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($i + 1, $sequences[$i]);
        }

        // Verify the underlying row reflects the final count
        $row = DB::table('document_sequences')
            ->where('document_type', 'work_order')
            ->where('year', (int) now()->format('Y'))
            ->where('month', (int) now()->format('n'))
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(50, (int) $row->last_number);
    }
}
