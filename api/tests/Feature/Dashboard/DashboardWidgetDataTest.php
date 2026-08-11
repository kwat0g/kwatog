<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardWidgetDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    public function test_every_registered_widget_resolves_a_live_data_source(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->firstOrFail()->id,
        ]);
        $keys = collect(app(DashboardLayoutService::class)->listAvailableWidgets($admin))
            ->pluck('key')->all();

        $summaries = app(DashboardWidgetDataService::class)->summaries($keys, $admin);

        // Every widget in the catalog must resolve — asserted against the
        // catalog itself rather than a hardcoded count, so adding a widget
        // without a resolver fails here instead of silently passing once
        // someone bumps the number.
        $this->assertCount(count($keys), $summaries);
        $this->assertSame(DashboardWidget::count(), count($summaries));
        $unavailable = collect($summaries)->where('available', false);
        $this->assertSame([], $unavailable->keys()->all(), $unavailable->pluck('helper', 'key')->toJson());
    }

    public function test_widget_endpoint_returns_live_self_data_and_filters_forbidden_keys(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->firstOrFail()->id,
            'employee_id' => $employee->id,
        ]);
        DB::table('attendances')->insert([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'regular_hours' => '7.50',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard/widget-data?keys[]=self.dtr_today&keys[]=finance.cash_position');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('7.50', $data['self.dtr_today']['value']);
        $this->assertSame('hours', $data['self.dtr_today']['kind']);
        $this->assertArrayNotHasKey('finance.cash_position', $data);
    }
}
