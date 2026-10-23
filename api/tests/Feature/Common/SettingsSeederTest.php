<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_fills_missing_settings_without_resetting_operator_values(): void
    {
        DB::table('settings')->where('key', 'company.punch_radius_m')->update([
            'value' => json_encode(325),
        ]);
        DB::table('settings')->where('key', 'modules.crm')->update([
            'value' => json_encode(false),
        ]);

        $this->seed(SettingsSeeder::class);

        $this->assertSame(325, json_decode((string) DB::table('settings')->where('key', 'company.punch_radius_m')->value('value'), true));
        $this->assertFalse(json_decode((string) DB::table('settings')->where('key', 'modules.crm')->value('value'), true));
        $this->assertSame(
            ['maintenance_supervisor'],
            json_decode((string) DB::table('settings')->where('key', 'maintenance.automation.actor_roles')->value('value'), true),
        );
    }
}
