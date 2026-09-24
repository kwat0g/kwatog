<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Modules\Landing\Models\NewsletterSubscriber;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear(md5('public-form127.0.0.1'));
    }

    public function test_guest_can_subscribe(): void
    {
        $response = $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'subscriber@example.com',
            'consent' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'subscriber@example.com',
        ]);

        $record = NewsletterSubscriber::where('email', 'subscriber@example.com')->first();
        $this->assertNotNull($record);
        $this->assertSame('subscribed', $record->status->value);
        $this->assertNotNull($record->consent_at, 'Acceptance of the privacy notice must be recorded.');
    }

    public function test_subscribe_requires_consent(): void
    {
        $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'no-consent@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('consent');

        $this->assertDatabaseMissing('newsletter_subscribers', [
            'email' => 'no-consent@example.com',
        ]);
    }

    public function test_subscribe_is_idempotent(): void
    {
        $first = $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'idempotent@example.com',
            'consent' => true,
        ]);
        $first->assertOk();

        RateLimiter::clear(md5('public-form127.0.0.1'));

        $second = $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'idempotent@example.com',
            'consent' => true,
        ]);
        $second->assertOk();

        $this->assertSame(
            1,
            NewsletterSubscriber::where('email', 'idempotent@example.com')->count(),
            'Duplicate subscription should upsert, not create a second row.'
        );
    }

    public function test_subscribe_requires_valid_email(): void
    {
        $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'notanemail',
            'consent' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_signed_get_unsubscribe_requires_post_confirmation(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'signed-unsubscribe@example.com',
        ]);
        $subscriber->forceFill(['status' => 'subscribed', 'consent_at' => now()])->save();
        $url = URL::signedRoute('landing.newsletter.unsubscribe', ['subscriber' => $subscriber->hash_id]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Confirm unsubscribing')
            ->assertSee('<form', false);
        $this->assertNull($subscriber->fresh()->unsubscribed_at);

        RateLimiter::clear(md5('public-form127.0.0.1'));
        $this->post($url)->assertOk();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }
}
