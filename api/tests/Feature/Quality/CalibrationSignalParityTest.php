<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Services\CalibrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CalibrationSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Cache::flush();
    }

    public function test_calibration_badges_match_due_and_overdue_register_filters(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        try {
            $service = app(CalibrationService::class);
            foreach ([
                ['GAUGE-DUE-30', '2026-11-25', null],
                ['GAUGE-DUE-TODAY', '2026-10-26', null],
                ['GAUGE-OVERDUE', '2026-10-25', null],
                ['GAUGE-ACTIVE', '2026-11-26', null],
                ['GAUGE-RETIRED', '2026-10-20', CalibrationStatus::Retired->value],
            ] as [$code, $nextDate, $status]) {
                $service->create([
                    'equipment_code' => $code,
                    'name' => $code,
                    'next_calibration_date' => $nextDate,
                    'frequency_days' => 365,
                    'status' => $status,
                ]);
            }

            $user = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
            ]);
            $badges = $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');

            foreach ([
                'calibration_due' => [CalibrationStatus::Due->value, 2],
                'calibration_overdue' => [CalibrationStatus::Overdue->value, 1],
            ] as $signal => [$status, $expectedCount]) {
                $this->assertSame($expectedCount, $badges[$signal]['count']);
                $this->assertSame($signal === 'calibration_overdue' ? 'danger' : 'warning', $badges[$signal]['severity']);
                $list = $this->getJson("/api/v1/quality/calibration?status={$status}&per_page=100")
                    ->assertOk()
                    ->assertJsonPath('meta.total', $expectedCount);
                $this->assertSame($expectedCount, count($list->json('data')));
                $this->assertSame([$status], array_values(array_unique(collect($list->json('data'))->pluck('status')->all())));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
