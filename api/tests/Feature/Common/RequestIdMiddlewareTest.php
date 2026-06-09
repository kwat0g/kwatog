<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use Tests\TestCase;

class RequestIdMiddlewareTest extends TestCase
{
    public function test_generates_uuid_when_no_inbound_header(): void
    {
        $response = $this->getJson('/api/v1/health');
        $id = $response->headers->get('X-Request-ID');

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function test_honors_inbound_request_id_header(): void
    {
        $custom = 'lb-edge-abc-12345';
        $response = $this->withHeaders(['X-Request-ID' => $custom])->getJson('/api/v1/health');

        $this->assertSame($custom, $response->headers->get('X-Request-ID'));
    }

    public function test_rejects_malformed_inbound_id_and_generates_fresh(): void
    {
        $response = $this->withHeaders(['X-Request-ID' => '<script>'])->getJson('/api/v1/health');
        $id = $response->headers->get('X-Request-ID');

        $this->assertNotSame('<script>', $id);
        $this->assertNotNull($id);
    }
}
