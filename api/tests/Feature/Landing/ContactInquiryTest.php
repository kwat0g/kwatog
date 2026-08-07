<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\LeadSource;
use App\Modules\CRM\Models\Lead;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Notifications\ContactInquiryReceivedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ContactInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear(md5('public-form127.0.0.1'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Juan dela Cruz',
            'company' => 'Toyota Philippines',
            'email' => 'juan@toyota.com.ph',
            'phone' => '+63 917 555 0101',
            'message' => 'We would like to discuss an ongoing order for wiper bushings.',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    // ─── public submit ───────────────────────────────────────────────────────

    public function test_guest_can_submit_contact_inquiry(): void
    {
        Notification::fake();

        $this->post('/api/v1/landing/contact-inquiry', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertCreated();

        $record = ContactInquiry::where('email', 'juan@toyota.com.ph')->first();
        $this->assertNotNull($record);
        $this->assertSame(ContactInquiryStatus::New, $record->status);
        $this->assertMatchesRegularExpression('/^INQ-\d{6}-\d{4}$/', $record->inquiry_no);

        Notification::assertSentOnDemand(ContactInquiryReceivedNotification::class);
    }

    public function test_company_and_phone_are_optional(): void
    {
        Notification::fake();

        // A job seeker or a student has neither; rejecting them would only
        // teach people to type a placeholder.
        $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload([
            'email' => 'applicant@example.com',
            'company' => null,
            'phone' => null,
        ]))->assertCreated();

        $this->assertDatabaseHas('contact_inquiries', ['email' => 'applicant@example.com', 'company' => null]);
    }

    public function test_validation_rejects_missing_and_oversized_fields(): void
    {
        $this->postJson('/api/v1/landing/contact-inquiry', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email', 'message']);

        $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload([
            'message' => str_repeat('a', 2001),
        ]))->assertStatus(422)->assertJsonValidationErrorFor('message');
    }

    public function test_submit_endpoint_is_throttled(): void
    {
        Notification::fake();

        // public-form is 10/min; the 11th must be refused.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload(['email' => "sender{$i}@example.com"]))
                ->assertCreated();
        }

        $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload(['email' => 'flood@example.com']))
            ->assertStatus(429);
    }

    // ─── inbox ───────────────────────────────────────────────────────────────

    public function test_inbox_requires_permission(): void
    {
        ContactInquiry::factory()->create();

        $this->getJson('/api/v1/crm/inquiries')->assertUnauthorized();

        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $this->actingAs($employee)->getJson('/api/v1/crm/inquiries')->assertForbidden();
    }

    public function test_inbox_lists_inquiries_and_never_exposes_integer_ids(): void
    {
        $inquiry = ContactInquiry::factory()->create(['email' => 'listed@example.com']);

        $response = $this->actingAs($this->admin())->getJson('/api/v1/crm/inquiries')->assertOk();

        $response->assertJsonPath('data.0.email', 'listed@example.com');
        $this->assertNotSame((string) $inquiry->id, $response->json('data.0.id'));
        $this->assertSame($inquiry->hash_id, $response->json('data.0.id'));
    }

    public function test_status_can_be_updated_but_converted_is_not_settable_by_hand(): void
    {
        $inquiry = ContactInquiry::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        // 'converted' would claim a lead exists. Only the convert action sets it.
        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'converted'])
            ->assertStatus(422);
    }

    // ─── convert to lead ─────────────────────────────────────────────────────

    public function test_convert_creates_website_sourced_lead_and_marks_inquiry(): void
    {
        $inquiry = ContactInquiry::factory()->create([
            'full_name' => 'Maria Santos',
            'company' => 'Nissan PH',
            'email' => 'maria@nissan.ph',
            'phone' => '+63 917 555 0202',
            'message' => 'Interested in discussing volume for relay covers.',
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/convert")
            ->assertCreated();

        $lead = Lead::where('email', 'maria@nissan.ph')->firstOrFail();
        $this->assertSame('Nissan PH', $lead->company_name);
        $this->assertSame('Maria Santos', $lead->contact_person);
        $this->assertSame(LeadSource::Website->value, $lead->source instanceof LeadSource ? $lead->source->value : $lead->source);
        $this->assertSame('Interested in discussing volume for relay covers.', $lead->notes);

        $inquiry->refresh();
        $this->assertSame(ContactInquiryStatus::Converted, $inquiry->status);
        $this->assertSame($lead->id, $inquiry->converted_to_lead_id);
    }

    public function test_convert_falls_back_to_sender_name_when_no_company(): void
    {
        // company_name is required on a lead; an individual is their own company.
        $inquiry = ContactInquiry::factory()->create([
            'full_name' => 'Solo Enquirer',
            'company' => null,
            'email' => 'solo@example.com',
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/convert")
            ->assertCreated();

        $this->assertSame('Solo Enquirer', Lead::where('email', 'solo@example.com')->value('company_name'));
    }

    public function test_convert_is_refused_twice(): void
    {
        $inquiry = ContactInquiry::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/convert")->assertCreated();
        $this->actingAs($admin)->postJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/convert")->assertStatus(422);

        $this->assertSame(1, Lead::where('email', $inquiry->email)->count());
    }
}
