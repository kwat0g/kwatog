<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Modules\Accounting\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LandingContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_live_company_contact_settings(): void
    {
        $response = $this->getJson('/api/v1/landing/contact');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'legal_name', 'address', 'phone', 'sales_email', 'company_email', 'public_url',
            ]]);

        $this->assertSame(
            (string) json_decode((string) DB::table('settings')->where('key', 'company.public_url')->value('value'), true),
            $response->json('data.public_url'),
        );
    }

    public function test_guest_can_read_landing_content_from_settings(): void
    {
        // oem_partners / trust_points / philippines_points are derived from LIVE
        // operational records, not from the landing.* settings — the controller
        // deliberately stopped publishing the seeded OEM list so a deployment
        // never claims customer relationships it does not have. An empty test
        // database therefore returns empty arrays, which is correct behaviour;
        // the assertions below need a customer to exist to have anything to show.
        Customer::factory()->create(['is_active' => true]);

        $response = $this->getJson('/api/v1/landing/content');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'oem_partners', 'quality_methods', 'trust_points',
                'philippines_points' => [['value', 'label']],
                'stats' => [['id', 'value', 'label']],
                'capabilities' => [['id', 'title', 'icon', 'blurb', 'points', 'tag']],
                'process_steps' => [['index', 'title', 'icon', 'body']],
                'quality_pillars' => [['id', 'title', 'icon', 'body']],
                'quality_policy' => ['standard', 'certification_title', 'certification_body', 'conformance_title', 'conformance_body'],
                'part_specs' => [['id', 'name', 'material', 'tolerance', 'application', 'feature']],
                'philippines_copy' => ['eyebrow', 'title', 'body'],
                'hero_copy' => ['line_one', 'line_two', 'line_three'],
            ]]);

        $this->assertNotEmpty($response->json('data.oem_partners'));
        $this->assertNotEmpty($response->json('data.quality_methods'));
        $this->assertNotEmpty($response->json('data.trust_points'));
        $this->assertNotEmpty($response->json('data.philippines_points'));
        $this->assertNotEmpty($response->json('data.stats'));
        $this->assertNotEmpty($response->json('data.capabilities'));
        $this->assertNotEmpty($response->json('data.process_steps'));
        $this->assertNotEmpty($response->json('data.quality_pillars'));
        $this->assertNotEmpty($response->json('data.quality_policy.standard'));
        $this->assertNotEmpty($response->json('data.part_specs'));
        $this->assertNotEmpty($response->json('data.philippines_copy.title'));
        $this->assertNotEmpty($response->json('data.hero_copy.line_one'));
    }
}
