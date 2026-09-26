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
 * The password-reset twin of the welcome-email bug.
 *
 * EmployeePasswordResetNotification is a QUEUED subclass of
 * PasswordResetNotification, whose parent held the temp password as
 * `private readonly`. SerializesModels cannot hydrate either modifier from a
 * SUBCLASS, so the queued job died on unserialize and no HR-reset employee
 * ever received their new password. UserProvisioningService's reset path uses
 * Notification::fake() in its own test, which never serializes anything — so
 * this has to go through a REAL round trip.
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
        $restored = unserialize(serialize(new EmployeePasswordResetNotification('Tmp-RST456!')));

        $html = (string) $restored->toMail(User::factory()->make([
            'name' => 'Juan Cruz',
            'email' => 'juan.cruz@ogami.test',
        ]))->render();

        $this->assertStringContainsString('Tmp-RST456!', $html);
    }
}
