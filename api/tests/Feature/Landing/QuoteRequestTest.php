<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Modules\Landing\Models\QuoteRequest;
use App\Modules\Landing\Notifications\QuoteRequestReceivedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuoteRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear(md5('public-form127.0.0.1'));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name'        => 'Juan dela Cruz',
            'company'          => 'Toyota Philippines',
            'email'            => 'juan@toyota.com.ph',
            'part_description' => 'Wiper bushing for Vios model year 2024, black PP resin.',
        ], $overrides);
    }

    // ─── happy paths ─────────────────────────────────────────────────────────

    public function test_guest_can_submit_quote_request_without_drawing(): void
    {
        Notification::fake();

        $response = $this->post(
            '/api/v1/landing/quote-request',
            $this->validPayload(),
            ['Accept' => 'application/json']
        );

        $response->assertCreated();

        $this->assertDatabaseHas('quote_requests', [
            'email' => 'juan@toyota.com.ph',
        ]);

        $record = QuoteRequest::where('email', 'juan@toyota.com.ph')->first();
        $this->assertNotNull($record, 'QuoteRequest row should exist.');
        $this->assertNotEmpty($record->request_no, 'request_no must not be empty.');
        $this->assertSame('new', $record->status->value);
    }

    public function test_guest_can_submit_quote_request_with_drawing(): void
    {
        Storage::fake('local');
        Notification::fake();

        $drawing = UploadedFile::fake()->create('drawing.pdf', 120, 'application/pdf');

        $response = $this->post(
            '/api/v1/landing/quote-request',
            array_merge($this->validPayload(['email' => 'with-drawing@toyota.com.ph']), [
                'drawing' => $drawing,
            ]),
            ['Accept' => 'application/json']
        );

        $response->assertCreated();

        $record = QuoteRequest::where('email', 'with-drawing@toyota.com.ph')->first();
        $this->assertNotNull($record, 'QuoteRequest row should exist.');

        Storage::disk('local')->assertExists($record->drawing_path);
        $this->assertSame('drawing.pdf', $record->drawing_original_name);

        Notification::assertSentOnDemand(QuoteRequestReceivedNotification::class);
    }

    // ─── validation failures ──────────────────────────────────────────────────

    public function test_quote_request_validation_fails_without_required_fields(): void
    {
        $response = $this->postJson('/api/v1/landing/quote-request', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('full_name');
        $response->assertJsonValidationErrorFor('company');
        $response->assertJsonValidationErrorFor('email');
        $response->assertJsonValidationErrorFor('part_description');
    }

    public function test_quote_request_rejects_disallowed_file_type(): void
    {
        Storage::fake('local');

        $malware = UploadedFile::fake()->create('malware.exe', 10);

        $response = $this->post(
            '/api/v1/landing/quote-request',
            array_merge($this->validPayload(), ['drawing' => $malware]),
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('drawing');
    }
}
