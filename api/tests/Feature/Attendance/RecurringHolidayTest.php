<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\HolidayType;
use App\Modules\Attendance\Services\HolidayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringHolidayTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_holidays_resolve_in_future_years_for_dtr_and_leave_ranges(): void
    {
        $service = app(HolidayService::class);
        $service->create([
            'name' => 'Annual Founding Day',
            'date' => '2026-04-09',
            'type' => HolidayType::Regular->value,
            'is_recurring' => true,
        ]);

        $holiday = $service->forDate(CarbonImmutable::parse('2027-04-09'));

        $this->assertNotNull($holiday);
        $this->assertSame(HolidayType::Regular, $holiday->type);
        $this->assertArrayHasKey(
            '2027-04-09',
            $service->datesBetween(
                CarbonImmutable::parse('2027-04-08'),
                CarbonImmutable::parse('2027-04-10'),
            ),
        );
    }
}
