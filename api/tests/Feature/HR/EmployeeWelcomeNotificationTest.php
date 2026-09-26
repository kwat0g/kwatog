<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\HR\Services\UserProvisioningService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * B3 — the welcome email carries the temporary password and is delivered by a
 * QUEUED notification, so it must survive the queue's serialize/unserialize
 * round trip.
 *
 * It did not. WelcomeNotification held the password as a `private readonly`
 * property, and SerializesModels cannot hydrate either modifier from a SUBCLASS:
 * private properties are rewritten under the concrete class name, and a readonly
 * property cannot be initialized from the child's scope. The queued job died on
 * unserialize, so no new hire ever received credentials.
 *
 * UserProvisioningTest missed this because it calls Notification::fake(), which
 * never serializes anything. These tests use the real (array) mail transport so
 * the message body is actually produced.
 */
class EmployeeWelcomeNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_the_notification_survives_serialization_with_its_password(): void
    {
        /** @var EmployeeWelcomeNotification $restored */
        $restored = unserialize(serialize(new EmployeeWelcomeNotification('Tmp-ABC123!')));

        $html = (string) $restored->toMail(User::factory()->make([
            'name' => 'Juan Cruz',
            'email' => 'juan.cruz@ogami.test',
        ]))->render();

        $this->assertStringContainsString('Tmp-ABC123!', $html);
    }

    public function test_a_queued_notification_delivers_mail_with_the_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Juan Cruz',
            'email' => 'juan.cruz@ogami.test',
        ]);

        // QUEUE_CONNECTION=sync in the suite, so this exercises the payload
        // serialization the worker performs in production.
        $user->notify(new EmployeeWelcomeNotification('Tmp-XYZ789!'));

        $this->assertStringContainsString('Tmp-XYZ789!', $this->lastMailHtml());
    }

    public function test_provisioning_delivers_the_welcome_email_with_a_temporary_password(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);

        $user = $svc->provisionForEmployee($this->makeEmployee());

        $this->assertTrue((bool) $user->must_change_password);
        $this->assertStringContainsString('Temporary Password:', $this->lastMailHtml());
    }

    /** The HTML body of the most recent message on the array transport. */
    private function lastMailHtml(): string
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertGreaterThan(0, $messages->count(), 'No mail was sent.');

        return (string) $messages->last()->getOriginalMessage()->getHtmlBody();
    }

    private function makeEmployee(): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no' => 'OGM-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name' => 'Juan', 'last_name' => 'Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
        ]);
    }
}
