<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify that queued HR password-reset mail survives serialization without a password.
 */
class EmployeePasswordResetNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_the_hr_password_reset_notification_survives_serialization(): void
    {
        /** @var EmployeePasswordResetNotification $restored */
        $restored = unserialize(serialize(new EmployeePasswordResetNotification('https://app.ogamiph.test/reset-password?token=reset-token', 60)));

        $html = (string) $restored->toMail(User::factory()->make([
            'name' => 'Juan Cruz',
            'email' => 'juan.cruz@ogami.test',
        ]))->render();

        $this->assertStringContainsString('https://app.ogamiph.test/reset-password?token=reset-token', $html);
        $this->assertStringNotContainsString('Temporary Password', $html);
    }
}
