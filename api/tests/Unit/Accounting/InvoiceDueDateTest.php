<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Models\Invoice;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class InvoiceDueDateTest extends TestCase
{
    public function test_due_today_is_current_until_the_next_manila_calendar_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 18:00:00', 'Asia/Manila'));

        try {
            $invoice = new Invoice(['status' => 'finalized', 'due_date' => '2026-10-27']);

            self::assertFalse($invoice->isOverdue());
            self::assertSame('current', $invoice->agingBucket());

            Carbon::setTestNow(Carbon::parse('2026-10-28 00:01:00', 'Asia/Manila'));

            self::assertTrue($invoice->isOverdue());
            self::assertSame('d1_30', $invoice->agingBucket());
        } finally {
            Carbon::setTestNow();
        }
    }
}
