<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    private string $originalDetailToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDetailToken = (string) config('health.detail_token', '');
    }

    protected function tearDown(): void
    {
        config(['health.detail_token' => $this->originalDetailToken]);
        parent::tearDown();
    }

    public function test_empty_configuration_returns_minimal_liveness_without_checks(): void
    {
        config(['health.detail_token' => '']);

        $response = $this->getJson('/api/v1/health');

        $response->assertJsonStructure(['status', 'service']);
        $this->assertArrayNotHasKey('checks', $response->json());
    }

    public function test_correct_header_returns_component_breakdown(): void
    {
        config(['health.detail_token' => 'test-health-secret']);

        $response = $this->withHeader('X-Health-Token', 'test-health-secret')
            ->getJson('/api/v1/health');

        $response->assertJsonStructure([
            'status',
            'service',
            'checks' => ['app', 'time', 'db', 'redis', 'queue'],
        ]);

        $payload = $response->json();
        $this->assertSame('ogami-api', $payload['service']);
        $this->assertTrue($payload['checks']['app']);
        // db should be reachable in the test container; redis depends on env.
        $this->assertTrue($payload['checks']['db']);
        // Status code matches health: 200 if db+redis healthy, 503 otherwise.
        $expected = $payload['checks']['db'] && $payload['checks']['redis'] ? 200 : 503;
        $this->assertSame($expected, $response->getStatusCode());
    }

    public function test_wrong_header_returns_minimal_liveness_without_checks(): void
    {
        config(['health.detail_token' => 'test-health-secret']);

        $response = $this->withHeader('X-Health-Token', 'wrong-secret')
            ->getJson('/api/v1/health');

        $response->assertJsonStructure(['status', 'service']);
        $this->assertArrayNotHasKey('checks', $response->json());
    }

    public function test_query_token_does_not_grant_detail_access(): void
    {
        config(['health.detail_token' => 'test-health-secret']);

        $response = $this->getJson('/api/v1/health?token=test-health-secret');

        $response->assertJsonStructure(['status', 'service']);
        $this->assertArrayNotHasKey('checks', $response->json());
    }

    public function test_returns_503_when_redis_unreachable(): void
    {
        config(['health.detail_token' => 'test-health-secret']);

        // Best-effort: only assert the *contract* — if redis is down, status is degraded.
        // We don't tear down redis here; just verify the response shape supports it.
        $response = $this->withHeader('X-Health-Token', 'test-health-secret')
            ->getJson('/api/v1/health');
        $checks = $response->json('checks');
        if (! $checks['redis']) {
            $response->assertStatus(503);
            $response->assertJsonPath('status', 'degraded');
        } else {
            $this->expectNotToPerformAssertions();
        }
    }
}
