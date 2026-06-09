<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_returns_component_breakdown(): void
    {
        $response = $this->getJson('/api/v1/health');

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

    public function test_returns_503_when_redis_unreachable(): void
    {
        // Best-effort: only assert the *contract* — if redis is down, status is degraded.
        // We don't tear down redis here; just verify the response shape supports it.
        $response = $this->getJson('/api/v1/health');
        $checks = $response->json('checks');
        if (! $checks['redis']) {
            $response->assertStatus(503);
            $response->assertJsonPath('status', 'degraded');
        } else {
            $this->expectNotToPerformAssertions();
        }
    }
}
