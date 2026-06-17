<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_can_download_quality_policy(): void
    {
        $response = $this->get('/api/v1/landing/quality-policy', [
            'Accept' => 'application/pdf',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            $response->headers->get('Content-Type', ''),
            'Response Content-Type should contain application/pdf.'
        );
    }
}
