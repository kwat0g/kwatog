<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Edge\Models\EdgeDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_ping_with_valid_token_returns_device_meta(): void
    {
        $device = EdgeDevice::create([
            'serial_number' => 'SCAN-1', 'name' => 'Test',
            'device_type'   => 'barcode_scanner', 'location' => 'X',
        ]);
        $token = $device->createToken('t', ['edge:scan'])->plainTextToken;

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/edge/v1/health');

        $r->assertOk();
        $r->assertJsonPath('data.name', 'Test');
        $r->assertJsonPath('data.device_type', 'barcode_scanner');
        $r->assertJsonPath('data.abilities.0', 'edge:scan');

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_health_ping_without_token_is_401(): void
    {
        $this->getJson('/api/v1/edge/v1/health')->assertStatus(401);
    }
}
