<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PunchSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_settings_and_permission_exist_after_migrate(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame(200.0, $s->requiredFloat('company.punch_radius_m'));
        $this->assertSame(100.0, $s->requiredFloat('company.punch_max_accuracy_m'));
        $this->assertSame(15, $s->requiredInt('attendance.punch_early_minutes'));
        $this->assertSame(240, $s->requiredInt('attendance.punch_late_minutes'));
        $this->assertDatabaseHas('permissions', ['slug' => 'attendance.punch.audit']);
    }

    public function test_admin_can_set_plant_centre_as_decimals(): void
    {
        $admin = User::factory()->withRole('system_admin')->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.latitude', ['value' => 14.2861])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.longitude', ['value' => 120.9346])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.latitude', ['value' => 95])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.punch_radius_m', ['value' => 0])->assertStatus(422);
    }
}
