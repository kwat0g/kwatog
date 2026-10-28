<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\CRM;

use App\Modules\CRM\Support\WarrantyAdvisory;
use PHPUnit\Framework\TestCase;

class WarrantyAdvisoryTest extends TestCase
{
    public function test_delivery_basis_returns_advisory_dates_without_deciding_eligibility(): void
    {
        $advisory = WarrantyAdvisory::from([
            'warranty_start_basis' => 'delivery',
            'warranty_duration_days' => 15,
            'warranty_report_window_days' => 7,
        ], '2026-10-20', '2026-10-21');

        $this->assertSame([
            'status' => 'advisory',
            'start_basis' => 'delivery',
            'start_date' => '2026-10-20',
            'report_by' => '2026-10-27',
            'warranty_through' => '2026-11-04',
            'duration_days' => 15,
            'report_window_days' => 7,
        ], $advisory);
    }

    public function test_missing_start_date_or_terms_requires_staff_review(): void
    {
        $advisory = WarrantyAdvisory::from([
            'warranty_start_basis' => 'invoice',
            'warranty_duration_days' => 15,
            'warranty_report_window_days' => 7,
        ], '2026-10-20', null);

        $this->assertSame('needs_review', $advisory['status']);
        $this->assertNull($advisory['start_date']);
        $this->assertNull($advisory['report_by']);
        $this->assertNull($advisory['warranty_through']);
        $this->assertNull(WarrantyAdvisory::from(null, '2026-10-20', '2026-10-21'));
    }
}
