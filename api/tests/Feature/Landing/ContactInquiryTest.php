<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Events\ContactInquiryChanged;
use App\Modules\Landing\Mail\ContactInquiryReceivedMail;
use App\Modules\Landing\Models\ContactInquiry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            'consent' => true,
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
        Mail::fake();

        $this->post('/api/v1/landing/contact-inquiry', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertCreated();

        $record = ContactInquiry::where('email', 'juan@toyota.com.ph')->first();
        $this->assertNotNull($record);
        $this->assertSame(ContactInquiryStatus::New, $record->status);
        $this->assertMatchesRegularExpression('/^INQ-\d{6}-\d{4}$/', $record->inquiry_no);
        $this->assertNotNull($record->consent_at, 'Acceptance of the privacy notice must be recorded.');

        Mail::assertQueued(ContactInquiryReceivedMail::class, function (ContactInquiryReceivedMail $mail) use ($record): bool {
            return $mail->inquiryNumber === $record->inquiry_no
                && $mail->inquiryHashId === $record->hash_id
                && ($mail->to[0]['address'] ?? null) === app(SettingsService::class)->requiredString('company.sales_inbox_email');
        });
    }

    public function test_company_and_phone_are_optional(): void
    {
        Mail::fake();

        // A job seeker or a student has neither; rejecting them would only
        // teach people to type a placeholder.
        $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload([
            'email' => 'applicant@example.com',
            'company' => null,
            'phone' => null,
        ]))->assertCreated();

        $this->assertDatabaseHas('contact_inquiries', ['email' => 'applicant@example.com', 'company' => null]);
    }

    public function test_user_agent_is_capped_to_the_database_column_length(): void
    {
        Mail::fake();

        $this->withHeaders(['User-Agent' => str_repeat('U', 300)])
            ->postJson('/api/v1/landing/contact-inquiry', $this->validPayload())
            ->assertCreated();

        $this->assertSame(
            255,
            strlen((string) ContactInquiry::query()
                ->where('email', 'juan@toyota.com.ph')
                ->value('user_agent')),
        );
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

    public function test_consent_is_required_and_not_stored_from_client_input(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/landing/contact-inquiry', $this->validPayload(['consent' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('consent');

        $this->assertDatabaseMissing('contact_inquiries', ['email' => 'juan@toyota.com.ph']);
    }

    public function test_submit_endpoint_is_throttled(): void
    {
        Mail::fake();

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

    public function test_inbox_is_closed_when_crm_feature_is_disabled(): void
    {
        app(SettingsService::class)->set('modules.crm', false, 'modules');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/inquiries')
            ->assertStatus(403)
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_inbox_lists_inquiries_and_never_exposes_integer_ids(): void
    {
        $inquiry = ContactInquiry::factory()->create(['email' => 'listed@example.com']);

        $response = $this->actingAs($this->admin())->getJson('/api/v1/crm/inquiries')->assertOk();

        $response->assertJsonPath('data.0.email', 'listed@example.com');
        $this->assertNotSame((string) $inquiry->id, $response->json('data.0.id'));
        $this->assertSame($inquiry->hash_id, $response->json('data.0.id'));
    }

    public function test_inquiry_resource_projects_current_actions_version_and_viewer_permission(): void
    {
        $inquiry = ContactInquiry::factory()->create(['status' => ContactInquiryStatus::New]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson("/api/v1/crm/inquiries/{$inquiry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.version', $inquiry->fresh()->updated_at?->toISOString())
            ->assertJsonPath('data.permissions.manage', true)
            ->assertJsonPath('data.available_actions.mark_in_progress', true)
            ->assertJsonPath('data.available_actions.close', true);

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.available_actions.mark_in_progress', false)
            ->assertJsonPath('data.available_actions.close', false);

        $viewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        UserPermissionOverride::query()->create([
            'user_id' => $viewer->id,
            'permission_id' => Permission::query()->where('slug', 'crm.inquiries.view')->value('id'),
            'type' => PermissionOverrideType::Grant,
            'granted_by' => $admin->id,
            'reason' => 'Read-only inquiry action projection coverage.',
        ]);
        $viewer->flushPermissionsCache();

        $this->actingAs($viewer)
            ->getJson("/api/v1/crm/inquiries/{$inquiry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.manage', false)
            ->assertJsonPath('data.available_actions.mark_in_progress', false)
            ->assertJsonPath('data.available_actions.close', false);
    }

    public function test_stale_expected_status_returns_typed_conflict(): void
    {
        $inquiry = ContactInquiry::factory()->create(['status' => ContactInquiryStatus::New]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", [
                'status' => 'closed',
                'expected_status' => 'new',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", [
                'status' => 'in_progress',
                'expected_status' => 'new',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'contact_inquiry_state_conflict');

        $this->assertSame(ContactInquiryStatus::Closed, $inquiry->fresh()->status);
    }

    public function test_inbox_clamps_non_positive_page_sizes(): void
    {
        ContactInquiry::factory()->count(2)->create();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/inquiries?per_page=-1')
            ->assertOk();

        $response->assertJsonPath('meta.per_page', 1);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_inbox_rejects_malformed_or_unbounded_list_filters(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/v1/crm/inquiries?status[]=new')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('status');

        $this->actingAs($admin)
            ->getJson('/api/v1/crm/inquiries?search[]=juan')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('search');

        $this->actingAs($admin)
            ->getJson('/api/v1/crm/inquiries?page[]=1')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('page');

        $this->actingAs($admin)
            ->getJson('/api/v1/crm/inquiries?per_page[]=25')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('per_page');

        $this->actingAs($admin)
            ->getJson('/api/v1/crm/inquiries?search='.str_repeat('a', 121))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('search');
    }

    public function test_status_can_be_updated_and_unknown_statuses_are_rejected(): void
    {
        $inquiry = ContactInquiry::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => ContactInquiry::class,
            'model_id' => $inquiry->id,
            'action' => 'updated',
        ]);

        // 'converted' belonged to the removed sales funnel — no longer a valid status.
        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'converted'])
            ->assertStatus(422);
    }

    public function test_closed_inquiry_is_terminal_and_cannot_be_reopened_by_status_patch(): void
    {
        $inquiry = ContactInquiry::factory()->create(['status' => ContactInquiryStatus::Closed]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/inquiries/{$inquiry->hash_id}/status", ['status' => 'in_progress'])
            ->assertUnprocessable();

        $this->assertSame(ContactInquiryStatus::Closed, $inquiry->fresh()->status);
    }

    public function test_retention_command_coalesces_expired_inquiry_deletions_into_a_refresh_signal(): void
    {
        $expiredAt = now()->subMonths(3);
        $expiredClosed = ContactInquiry::factory()->create([
            'status' => ContactInquiryStatus::Closed,
            'created_at' => $expiredAt,
            'updated_at' => $expiredAt,
        ]);
        $expiredSoftDeleted = ContactInquiry::factory()->create([
            'status' => ContactInquiryStatus::New,
            'created_at' => $expiredAt,
            'updated_at' => $expiredAt,
        ]);
        $expiredSoftDeleted->forceFill(['deleted_at' => $expiredAt])->saveQuietly();
        $oldOpen = ContactInquiry::factory()->create([
            'status' => ContactInquiryStatus::New,
            'created_at' => $expiredAt,
            'updated_at' => $expiredAt,
        ]);
        $recentClosed = ContactInquiry::factory()->create([
            'status' => ContactInquiryStatus::Closed,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        self::assertSame(0, Artisan::call('landing:prune-pii', ['--months' => 1]));

        $this->assertDatabaseMissing('contact_inquiries', ['id' => $expiredClosed->id]);
        $this->assertDatabaseMissing('contact_inquiries', ['id' => $expiredSoftDeleted->id]);
        $this->assertDatabaseHas('contact_inquiries', ['id' => $oldOpen->id]);
        $this->assertDatabaseHas('contact_inquiries', ['id' => $recentClosed->id]);

        $messages = DB::table('event_outbox')
            ->where('event_type', ContactInquiryChanged::class)
            ->where('payload', 'like', '%"action":"deleted"%')
            ->get();
        $this->assertCount(1, $messages, 'Multiple expired records in one run share one inbox refresh signal.');

        $storedPayload = json_decode((string) $messages->first()->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('deleted', $storedPayload['action']);
        $this->assertNull($storedPayload['entityHashId']);
        $payload = app(OutboxEventCodec::class)
            ->decode(ContactInquiryChanged::class, $storedPayload)
            ->broadcastWith();
        $this->assertSame('contact_inquiry', $payload['entity_type']);
        $this->assertNull($payload['entity_id'], 'A bulk-delete signal must not claim one deleted record.');
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('message', $payload);
    }
}
