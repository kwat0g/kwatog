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

    public function test_complaint_update_email_renders_enum_values(): void
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
                && $mail->complaint->is($complaint);
        });

        $this->assertStringContainsString('Open', $rendered);
        $this->assertStringContainsString('Critical', $rendered);
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
