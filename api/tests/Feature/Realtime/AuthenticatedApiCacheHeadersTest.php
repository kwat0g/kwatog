<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Common\Middleware\PreventAuthenticatedApiCaching;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedApiCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_api_responses_are_private_and_never_stored(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/auth/user')
            ->assertOk();

        $cacheControl = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $this->assertContains('max-age=0', $cacheControl);
        $this->assertContains('must-revalidate', $cacheControl);
        $response
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertHeader('Vary', 'Cookie, Authorization');
    }

    public function test_rejected_authenticated_api_requests_are_never_stored(): void
    {
        $response = $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $cacheControl = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $response->assertHeader('Vary', 'Cookie, Authorization');
    }

    public function test_forbidden_authenticated_api_responses_are_never_stored(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();

        $cacheControl = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $response->assertHeader('Vary', 'Cookie, Authorization');
    }

    public function test_authenticated_cache_policy_preserves_existing_vary_dimensions(): void
    {
        $response = response()->json(['ok' => true]);
        $response->headers->set('Vary', 'Origin, Authorization');

        PreventAuthenticatedApiCaching::apply($response);

        $this->assertSame('Origin, Authorization, Cookie', $response->headers->get('Vary'));
    }
}
