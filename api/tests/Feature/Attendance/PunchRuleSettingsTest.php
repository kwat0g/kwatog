<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PunchRuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_rule_settings_exist_with_their_defaults(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame(15, $s->requiredInt('attendance.punch_early_out_grace_minutes'));
        $this->assertSame(30, $s->requiredInt('attendance.punch_min_work_minutes'));
        $this->assertSame(240, $s->requiredInt('attendance.punch_late_in_cutoff_minutes'));
    }

    public function test_admin_can_change_them_within_range_and_not_outside(): void
    {
        $admin = User::factory()->withRole('system_admin')->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_early_out_grace_minutes', ['value' => 20])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_early_out_grace_minutes', ['value' => 500])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_min_work_minutes', ['value' => 241])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_late_in_cutoff_minutes', ['value' => 10])->assertStatus(422);
    }
}
