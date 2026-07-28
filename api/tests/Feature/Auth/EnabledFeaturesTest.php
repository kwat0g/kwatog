<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnabledFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_payload_includes_newer_enabled_modules_and_honors_disabled_modules(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.forecasting', true, 'modules');
        $settings->set('modules.b2b_portals', true, 'modules');
        $settings->set('modules.budgeting', false, 'modules');

        $features = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->json('data.features');

        $this->assertContains('forecasting', $features);
        $this->assertContains('b2b_portals', $features);
        $this->assertNotContains('budgeting', $features);
    }
}
