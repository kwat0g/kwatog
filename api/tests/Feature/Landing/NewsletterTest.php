<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Modules\Landing\Models\NewsletterSubscriber;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
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
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'subscriber@example.com',
        ]);

        $record = NewsletterSubscriber::where('email', 'subscriber@example.com')->first();
        $this->assertNotNull($record);
        $this->assertSame('subscribed', $record->status->value);
    }

    public function test_subscribe_is_idempotent(): void
    {
        $first = $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'idempotent@example.com',
        ]);
        $first->assertOk();

        RateLimiter::clear(md5('public-form127.0.0.1'));

        $second = $this->postJson('/api/v1/landing/newsletter', [
            'email' => 'idempotent@example.com',
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
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }
}
