<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quote;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales pipeline end-to-end (audit §3.1 follow-up).
 *
 * The lead → opportunity → quote funnel API has no UI and no tests; these pin
 * the transitions the pipeline UI will drive: lead lifecycle, conversion
 * guards, opportunity stage advancement, win/lose closure, and quote
 * generation from a won pipeline.
 */
class SalesPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', [
                'crm.leads.manage',
                'crm.opportunities.manage',
                'crm.quotes.manage',
            ])->pluck('id')->all(),
        );
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->customer = Customer::factory()->create();
    }

    private function leadPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name'    => 'Toyota Motor PH',
            'contact_person'  => 'Juan Dela Cruz',
            'email'           => 'juan@toyota.ph',
            'phone'           => '+639171234567',
            'source'          => 'referral',
            'estimated_value' => '2500000',
            'notes'           => 'Interested in wiper bushings.',
        ], $overrides);
    }

    // ─── Lead lifecycle ──────────────────────────────────────────────────────

    public function test_lead_create_generates_number_and_defaults_to_new(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/crm/leads', $this->leadPayload());

        $response->assertCreated()
            ->assertJsonPath('data.company_name', 'Toyota Motor PH')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonStructure(['data' => ['lead_number', 'id', 'source_label']]);

        $this->assertMatchesRegularExpression('/^LEAD-\d{6}-\d{4}$/', $response->json('data.lead_number'));
    }

    public function test_lead_requires_source_and_valid_email(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/crm/leads', $this->leadPayload([
            'source' => 'bogus',
        ]))->assertStatus(422);

        $this->actingAs($this->user)->postJson('/api/v1/crm/leads', $this->leadPayload([
            'email' => 'not-an-email',
        ]))->assertStatus(422);
    }

    public function test_lead_qualify_then_disqualify(): void
    {
        $lead = $this->postLead();

        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify")
            ->assertOk()->assertJsonPath('data.status', 'qualified');

        // Cannot qualify twice.
        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify")
            ->assertStatus(422);

        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/disqualify", [
            'reason' => 'Budget not approved for this fiscal year',
        ])->assertOk()->assertJsonPath('data.status', 'disqualified');

        $this->assertDatabaseHas('leads', [
            'id'    => $lead->id,
            'notes' => "Interested in wiper bushings.\n\n[Disqualified: Budget not approved for this fiscal year]",
        ]);
    }

    public function test_convert_requires_qualified_lead_and_customer(): void
    {
        $lead = $this->postLead();

        // Not qualified yet.
        $this->actingAs($this->user)->postJson("/api/v1/crm/leads/{$lead->hash_id}/convert")
            ->assertStatus(422);

        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify");

        // No customer linked → must refuse.
        $this->actingAs($this->user)->postJson("/api/v1/crm/leads/{$lead->hash_id}/convert")
            ->assertStatus(422);
    }

    public function test_convert_qualified_lead_creates_opportunity_and_marks_converted(): void
    {
        $lead = $this->postLead(['customer_id' => $this->customer->hash_id]);
        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify");

        $response = $this->actingAs($this->user)->postJson("/api/v1/crm/leads/{$lead->hash_id}/convert");

        $response->assertOk()
            ->assertJsonPath('data.stage', 'prospecting')
            ->assertJsonPath('data.customer.id', $this->customer->hash_id)
            ->assertJsonPath('data.title', 'Toyota Motor PH');

        $this->assertDatabaseHas('leads', [
            'id'                            => $lead->id,
            'status'                        => 'converted',
        ]);
        $lead->refresh();
        $this->assertNotNull($lead->converted_to_opportunity_id);

        // Double conversion refused.
        $this->actingAs($this->user)->postJson("/api/v1/crm/leads/{$lead->hash_id}/convert")
            ->assertStatus(422);
    }

    // ─── Opportunity pipeline ────────────────────────────────────────────────

    private function postLead(array $overrides = []): Lead
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/crm/leads', $this->leadPayload($overrides));
        $response->assertCreated();
        return Lead::query()->findOrFail(app('hashids')->decode($response->json('data.id'))[0]);
    }

    private function qualifiedOpportunity(): Opportunity
    {
        $lead = $this->postLead(['customer_id' => $this->customer->hash_id]);
        $this->actingAs($this->user)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify");
        $response = $this->actingAs($this->user)->postJson("/api/v1/crm/leads/{$lead->hash_id}/convert");
        return Opportunity::query()->findOrFail(app('hashids')->decode($response->json('data.id'))[0]);
    }

    public function test_opportunity_advances_through_stages(): void
    {
        $opp = $this->qualifiedOpportunity();

        foreach (['needs_analysis', 'proposal', 'negotiation'] as $stage) {
            $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/advance")
                ->assertOk()->assertJsonPath('data.stage', $stage);
        }

        // Negotiation is the last advanceable stage — must refuse and demand win/lose.
        $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/advance")
            ->assertStatus(422);
    }

    public function test_opportunity_win_closes_with_probability_100(): void
    {
        $opp = $this->qualifiedOpportunity();

        $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/win")
            ->assertOk()
            ->assertJsonPath('data.stage', 'won')
            ->assertJsonPath('data.probability', 100)
            ->assertJsonPath('data.is_terminal', true);

        // Won opportunities are closed to further movement and edits.
        $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/advance")
            ->assertStatus(422);
        $this->actingAs($this->user)->putJson("/api/v1/crm/opportunities/{$opp->hash_id}", [
            'title' => 'Nope',
        ])->assertStatus(422);
    }

    public function test_opportunity_lose_requires_reason(): void
    {
        $opp = $this->qualifiedOpportunity();

        $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/lose", [])
            ->assertStatus(422);

        $this->actingAs($this->user)->patchJson("/api/v1/crm/opportunities/{$opp->hash_id}/lose", [
            'reason' => 'Lost to a competitor on price.',
        ])->assertOk()
            ->assertJsonPath('data.stage', 'lost')
            ->assertJsonPath('data.lost_reason', 'Lost to a competitor on price.');
    }

    public function test_create_quote_from_opportunity_returns_draft_quote(): void
    {
        $opp = $this->qualifiedOpportunity();

        $response = $this->actingAs($this->user)->postJson("/api/v1/crm/opportunities/{$opp->hash_id}/create-quote");

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_amount', '0.00');

        $this->assertDatabaseHas('quotes', [
            'opportunity_id' => $opp->id,
            'customer_id'    => $this->customer->id,
            'status'         => 'draft',
        ]);
    }

    public function test_pipeline_actions_require_manage_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $lead = $this->postLead();

        $this->actingAs($viewer)->postJson('/api/v1/crm/leads', $this->leadPayload())->assertStatus(403);
        $this->actingAs($viewer)->patchJson("/api/v1/crm/leads/{$lead->hash_id}/qualify")->assertStatus(403);
    }
}
