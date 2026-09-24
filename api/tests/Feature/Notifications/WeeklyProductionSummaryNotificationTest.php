<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklyProductionSummaryNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_weekly_production_summary_reaches_a_valid_recipient(): void
    {
        Notification::fake();
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
            'email' => 'production.manager@ogami.test',
            'is_active' => true,
        ]);

        $this->artisan('production:send-weekly-summary')->assertSuccessful();

        Notification::assertSentTo($manager, WeeklyProductionSummary::class);
    }
}
