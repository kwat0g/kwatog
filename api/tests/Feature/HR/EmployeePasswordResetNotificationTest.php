<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\AdminPasswordResetNotification;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify legacy reset links and new encrypted temporary-password mail serialize safely.
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

    public function test_admin_reset_mail_contains_encrypted_temporary_password_and_forces_change(): void
    {
        $notification = new AdminPasswordResetNotification('6f3a0b9c7d1e4f82!Aa1');
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);

        /** @var AdminPasswordResetNotification $restored */
        $restored = unserialize(serialize($notification));
        $html = (string) $restored->toMail(User::factory()->make([
            'name' => 'Juan Cruz',
            'email' => 'juan.cruz@ogami.test',
        ]))->render();

        $this->assertStringContainsString('6f3a0b9c7d1e4f82!Aa1', $html);
        $this->assertStringContainsString('change this password', $html);
        $this->assertStringNotContainsString('reset-password?token=', $html);
    }
}
