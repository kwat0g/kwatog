<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\CustomerComplaintUpdated;
use App\Modules\CRM\Listeners\EmailCustomerOnComplaintUpdated;
use App\Modules\CRM\Mail\CustomerComplaintUpdateMail;
use App\Modules\CRM\Models\CustomerComplaint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ComplaintEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    public function test_complaint_update_email_uses_safe_customer_facing_fields(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'customer@example.test',
            'contact_person' => 'A. Customer',
        ]);
        $creator = User::factory()->withRole('system_admin')->create();
        $complaint = $this->complaint($customer, $creator);

        app(EmailCustomerOnComplaintUpdated::class)
            ->handle(new CustomerComplaintUpdated($complaint, 'created'));

        $rendered = '';
        Mail::assertQueued(CustomerComplaintUpdateMail::class, function (CustomerComplaintUpdateMail $mail) use (&$rendered, $complaint): bool {
            $rendered = $mail->render();

            return $mail->hasTo('customer@example.test')
                && $mail->snapshot['complaint_number'] === $complaint->complaint_number;
        });

        $this->assertStringContainsString('Open', $rendered);
        $this->assertStringNotContainsString('Critical', $rendered);
        $this->assertStringNotContainsString('Notification rendering test', $rendered);
        $this->assertStringNotContainsString('Affected quantity', $rendered);
        $this->assertStringContainsString(
            rtrim((string) config('app.frontend_url'), '/').'/portal/customer/complaints',
            $rendered,
        );
    }

    public function test_complaint_notification_keeps_event_time_recipient_and_status(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'original@example.test',
            'contact_person' => 'Original Contact',
        ]);
        $creator = User::factory()->withRole('system_admin')->create();
        $complaint = $this->complaint($customer, $creator);
        $event = new CustomerComplaintUpdated($complaint->fresh(), 'created');

        $customer->update(['email' => 'changed@example.test', 'contact_person' => 'Changed Contact']);
        $complaint->update(['status' => 'investigating']);

        app(EmailCustomerOnComplaintUpdated::class)->handle($event);

        Mail::assertQueued(CustomerComplaintUpdateMail::class, function (CustomerComplaintUpdateMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('original@example.test')
                && $mail->snapshot['status_label'] === 'Open'
                && str_contains($html, 'Original Contact')
                && ! str_contains($html, 'Changed Contact')
                && str_contains($html, 'Open')
                && ! str_contains($html, 'Investigating');
        });
    }

    public function test_complaint_email_sanitizes_customer_markdown(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'customer@example.test',
            'contact_person' => '[Billing](https://evil.example)<script>alert(1)</script>',
        ]);
        $creator = User::factory()->withRole('system_admin')->create();
        $mail = new CustomerComplaintUpdateMail(
            (new CustomerComplaintUpdated($this->complaint($customer, $creator), 'created'))->snapshotValues(),
        );
        $html = $mail->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_missing_customer_email_creates_an_internal_fallback(): void
    {
        $customer = Customer::factory()->create(['email' => null]);
        $creator = User::factory()->withRole('system_admin')->create();
        $complaint = $this->complaint($customer, $creator);

        app(EmailCustomerOnComplaintUpdated::class)
            ->handle(new CustomerComplaintUpdated($complaint, 'created'));

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $creator->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    private function complaint(Customer $customer, User $creator): CustomerComplaint
    {
        return CustomerComplaint::create([
            'complaint_number' => 'CC-EMAIL-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'received_date' => today(),
            'severity' => 'critical',
            'status' => 'open',
            'description' => 'Notification rendering test',
            'affected_quantity' => 1,
            'created_by' => $creator->id,
        ]);
    }
}
